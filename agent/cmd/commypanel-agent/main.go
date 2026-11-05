// Command commypanel-agent performs privileged host operations for the
// CommyPanel web app. It listens on a Unix socket only and exposes a fixed
// list of typed, validated actions; it never runs arbitrary commands.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"os/user"
	"runtime"
	"strconv"
	"syscall"
	"time"

	"commypanel/agent/internal/certs"
	"commypanel/agent/internal/dbtool"
	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/nginx"
	"commypanel/agent/internal/server"
	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/system"
	"commypanel/agent/internal/terminal"
	"commypanel/agent/internal/usage"
	"commypanel/agent/internal/volumefs"
)

var version = "dev"

type uidList []uint32

func (u *uidList) String() string { return fmt.Sprint(*u) }
func (u *uidList) Set(v string) error {
	n, err := strconv.ParseUint(v, 10, 32)
	if err != nil {
		return err
	}
	*u = append(*u, uint32(n))
	return nil
}

func main() {
	if err := run(); err != nil {
		slog.Error("agent stopped", "err", err)
		os.Exit(1)
	}
}

func run() error {
	var (
		socket     = flag.String("socket", "/run/commypanel/agent.sock", "Unix socket path")
		group      = flag.String("socket-group", "commypanel", "group that may connect to the socket")
		dataDir    = flag.String("data-dir", "/var/lib/commypanel", "panel data directory")
		stateDir   = flag.String("state-dir", "/var/lib/commypanel-agent", "the agent's own scratch space and records; root only, outside the data directory")
		sitesDir   = flag.String("nginx-sites-dir", "/etc/nginx/commypanel", "directory nginx includes site configs from")
		dockerSock = flag.String("docker-socket", "/var/run/docker.sock", "Docker Engine socket")
		defMemory  = flag.Int("default-memory-mb", 512, "memory limit for containers that do not set one")
		defCPUs    = flag.Float64("default-cpus", 1, "CPU limit for containers that do not set one")
		buildMem   = flag.Int("build-memory-mb", 2048, "memory limit for image builds")
		buildCPUs  = flag.Float64("build-cpus", 2, "CPU limit for image builds")
		termSocket = flag.String("terminal-socket", "", "Unix socket for browser terminals (WebSocket); empty turns terminals off")
		toolSocket = flag.String("dbtool-socket", "", "Unix socket for the web side of database tools (phpMyAdmin, pgAdmin); empty turns them off")
		toolsPort  = flag.Int("tools-port", 2053, "the port database tools are served on, next to the panel's own domain")
		toolGroup  = flag.String("dbtool-socket-group", "www-data", "group that may connect to the database tool socket (the web server's)")
		termGroup  = flag.String("terminal-socket-group", "www-data", "group that may connect to the terminal socket (the web server's)")
		dryRun     = flag.Bool("dry-run", false, "write configs but never run nginx or Docker (development)")
		allowed    uidList
	)
	flag.Var(&allowed, "allow-uid", "UID allowed to call the agent (repeatable; the panel's user)")
	showVersion := flag.Bool("version", false, "print version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println(version)
		return nil
	}

	var authorize server.Authorizer
	switch {
	case runtime.GOOS == "linux" && len(allowed) > 0:
		authorize = server.AllowUIDs(allowed...)
	case *dryRun && runtime.GOOS != "linux":
		slog.Warn("peer credentials unavailable on this OS; accepting all local callers (dry-run only)")
		authorize = server.AllowAll()
	default:
		return errors.New("at least one --allow-uid is required (the panel's user ID)")
	}

	if !*dryRun && os.Geteuid() != 0 {
		return errors.New("must run as root (use --dry-run for development)")
	}

	// The agent's private folder is checked before anything is written to it.
	if err := statedir.Prepare(*stateDir); err != nil {
		return fmt.Errorf("state directory: %w", err)
	}

	mgr := nginx.NewManager(nginx.Config{SitesDir: *sitesDir, DataDir: *dataDir, StateDir: *stateDir, DryRun: *dryRun, TerminalSocket: *termSocket, ToolsSocket: *toolSocket, ToolsPort: *toolsPort}, nil)
	dockerMgr := docker.NewManager(docker.Config{
		DataDir: *dataDir, StateDir: *stateDir, DryRun: *dryRun,
		DefaultMemoryMB: *defMemory, DefaultCPUs: *defCPUs,
		BuildMemoryMB: *buildMem, BuildCPUs: *buildCPUs,
	}, docker.NewUnixEngine(*dockerSock))
	dockerMgr.CleanScratch()
	certMgr := certs.NewManager(certs.Config{DataDir: *dataDir, StateDir: *stateDir, DryRun: *dryRun, UserAgent: "commypanel-agent/" + version})
	sampler := usage.New(usage.Config{DataDir: *dataDir, Containers: dockerMgr})
	var tickets *terminal.Tickets
	if *termSocket != "" {
		tickets = terminal.NewTickets(terminal.TicketTTL, 32)
	}
	var tools *dbtool.Manager
	toolCtx, endTools := context.WithCancel(context.Background())
	defer endTools()
	if *toolSocket != "" && !*dryRun {
		tools = dbtool.New(toolCtx, dockerMgr, dbtool.Config{})
		// A tool must not outlive the agent that watches it and closes it after 15 minutes.
		defer tools.Shutdown()
	}
	srv := server.New(server.Options{Nginx: mgr, DBTools: toolsFor(tools), Docker: dockerMgr, Volumes: volumefs.New(*dataDir), Terminal: ticketIssuer(tickets), Certs: certMgr, Unit: system.NewDockerUnit(*dryRun), Usage: sampler,
		Authorize: authorize, Version: version, DryRun: *dryRun,
		System:    func() system.Status { return system.Collect(*dataDir) },
		Info:      system.NewReader(*dataDir).Info,
		Addresses: system.ReadAddresses,
	})

	ln, err := listen(*socket, *group, *dryRun)
	if err != nil {
		return err
	}

	httpSrv := &http.Server{
		Handler:           srv.Handler(),
		ConnContext:       server.ConnContext,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = httpSrv.Shutdown(shutdown)
	}()

	go sampler.Run(ctx)

	if err := mgr.EnsureDefaultSite(ctx); err != nil {
		slog.Warn("could not install the default port-80 site; certificates for domains without a live app will fail", "err", err)
	}

	if tickets != nil {
		termLn, err := listen(*termSocket, *termGroup, *dryRun)
		if err != nil {
			return fmt.Errorf("terminal socket: %w", err)
		}
		termSrv := &http.Server{
			Handler: (&terminal.Server{
				Tickets: tickets,
				Limits:  terminal.DefaultLimits,
				Open: func(ctx context.Context, container string, cols, rows int) (terminal.Shell, error) {
					sh, err := dockerMgr.OpenShell(ctx, container, cols, rows)
					if err != nil {
						return nil, err
					}
					return sh, nil
				},
			}).Handler(),
			ReadHeaderTimeout: 5 * time.Second, // no overall timeout: a session lasts hours
		}
		go func() {
			<-ctx.Done()
			shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			_ = termSrv.Shutdown(shutdown)
		}()
		go func() {
			if err := termSrv.Serve(termLn); !errors.Is(err, http.ErrServerClosed) {
				slog.Error("terminal socket stopped", "err", err)
			}
		}()
		slog.Info("terminals enabled", "socket", *termSocket)
	}

	go func() {
		<-ctx.Done()
		endTools()
	}()
	if tools != nil {
		toolLn, err := listen(*toolSocket, *toolGroup, *dryRun)
		if err != nil {
			return fmt.Errorf("database tool socket: %w", err)
		}
		// Whatever an earlier run left behind (it kept sessions in memory only) is removed first.
		tools.Cleanup(ctx)
		go tools.RunReaper(ctx)
		toolSrv := &http.Server{
			Handler:           tools,
			ReadHeaderTimeout: 10 * time.Second,
			ReadTimeout:       15 * time.Minute, // a dump being imported
			WriteTimeout:      15 * time.Minute,
			IdleTimeout:       60 * time.Second,
			MaxHeaderBytes:    32 << 10,
		}
		go func() {
			<-ctx.Done()
			shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			_ = toolSrv.Shutdown(shutdown)
		}()
		go func() {
			if err := toolSrv.Serve(toolLn); !errors.Is(err, http.ErrServerClosed) {
				slog.Error("database tool socket stopped", "err", err)
			}
		}()
		slog.Info("database tools enabled", "socket", *toolSocket)
	}

	slog.Info("agent listening", "socket", *socket, "version", version, "dry_run", *dryRun)
	if err := httpSrv.Serve(ln); !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// toolsFor avoids handing the server a typed nil: with database tools off it
// must see a nil interface so it can answer "not enabled".
func toolsFor(t *dbtool.Manager) server.DBTools {
	if t == nil {
		return nil
	}
	return t
}

// ticketIssuer avoids handing the server a typed nil: with terminals off the
// server must see a nil interface so it can answer "not enabled".
func ticketIssuer(t *terminal.Tickets) server.Terminal {
	if t == nil {
		return nil
	}
	return t
}

// listen creates the Unix socket with restrictive permissions: root-owned,
// group-accessible, and removed on shutdown.
func listen(path, group string, dryRun bool) (net.Listener, error) {
	if err := os.MkdirAll(dirOf(path), 0o755); err != nil {
		return nil, err
	}
	_ = os.Remove(path)

	ln, err := net.Listen("unix", path)
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(path, 0o660); err != nil {
		ln.Close()
		return nil, err
	}
	if g, err := user.LookupGroup(group); err == nil {
		gid, _ := strconv.Atoi(g.Gid)
		if err := os.Chown(path, -1, gid); err != nil && !dryRun {
			ln.Close()
			return nil, fmt.Errorf("chown socket: %w", err)
		}
	} else if !dryRun {
		ln.Close()
		return nil, fmt.Errorf("group %q not found: %w", group, err)
	}
	return ln, nil
}

func dirOf(path string) string {
	for i := len(path) - 1; i >= 0; i-- {
		if path[i] == '/' {
			return path[:i]
		}
	}
	return "."
}
