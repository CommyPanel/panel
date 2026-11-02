// Package nginx renders, validates and applies per-site nginx configs.
package nginx

import (
	"bytes"
	"commypanel/agent/internal/statedir"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"text/template"
	"time"

	"commypanel/agent/internal/validate"
)

// Site is the only input the panel may send for an nginx site. Raw nginx
// config is never accepted; the config is rendered from this validated data.
type Site struct {
	Name         string   `json:"name"`
	Domains      []string `json:"domains"`
	UpstreamPort int      `json:"upstream_port"`
	Certificate  string   `json:"certificate,omitempty"`
	// Redirects send some of the site's domains to another one of them,
	// for example example.com to www.example.com.
	Redirects []Redirect `json:"redirects,omitempty"`
	// Custom is the user's extra directives for the serving server block. It
	// is parsed and checked against an allow-list (see custom.go) and rendered
	// from the parsed result, never embedded as typed.
	Custom string `json:"custom,omitempty"`
}

// Redirect is a permanent redirect between two of a site's domains.
type Redirect struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// served returns the domains that proxy to the app (the ones not redirected).
func (s Site) served() []string {
	redirected := map[string]bool{}
	for _, r := range s.Redirects {
		redirected[r.From] = true
	}
	var out []string
	for _, d := range s.Domains {
		if !redirected[d] {
			out = append(out, d)
		}
	}
	return out
}

func (s Site) Validate() error {
	if err := validate.Name(s.Name); err != nil {
		return err
	}
	if len(s.Domains) == 0 || len(s.Domains) > 50 {
		return &validate.InputError{Msg: "a site needs between 1 and 50 domains"}
	}
	for _, d := range s.Domains {
		if err := validate.Domain(d); err != nil {
			return err
		}
	}
	if err := validate.Port(s.UpstreamPort); err != nil {
		return err
	}
	if err := s.validateRedirects(); err != nil {
		return err
	}
	if s.Certificate != "" {
		if err := validate.Name(s.Certificate); err != nil {
			return err
		}
	}
	_, err := CheckCustom(s.Custom)
	return err
}

func (s Site) validateRedirects() error {
	if len(s.Redirects) > 25 {
		return &validate.InputError{Msg: "too many redirects"}
	}
	known := map[string]bool{}
	for _, d := range s.Domains {
		known[d] = true
	}
	from := map[string]bool{}
	for _, r := range s.Redirects {
		if err := validate.Domain(r.From); err != nil {
			return err
		}
		if err := validate.Domain(r.To); err != nil {
			return err
		}
		if r.From == r.To || !known[r.From] || !known[r.To] {
			return &validate.InputError{Msg: "a redirect must go between two different domains of the site"}
		}
		if from[r.From] {
			return &validate.InputError{Msg: "a domain can only redirect once"}
		}
		from[r.From] = true
	}
	for _, r := range s.Redirects {
		if from[r.To] {
			return &validate.InputError{Msg: "a redirect cannot point at a domain that redirects itself"}
		}
	}
	if len(s.served()) == 0 {
		return &validate.InputError{Msg: "a site needs at least one domain that is not redirected"}
	}
	return nil
}

// Runner executes host commands. It exists so tests never run real nginx.
type Runner interface {
	Run(ctx context.Context, name string, args ...string) ([]byte, error)
}

type execRunner struct{}

func (execRunner) Run(ctx context.Context, name string, args ...string) ([]byte, error) {
	return exec.CommandContext(ctx, name, args...).CombinedOutput()
}

// Config locates the files the manager works with.
type Config struct {
	SitesDir string // directory included by the main nginx.conf
	DataDir  string // panel data dir
	StateDir string // the agent's private folder (certificates, ACME webroot)
	DryRun   bool   // write files but never run nginx
	// TerminalSocket is the agent's terminal socket. When set, the panel's own
	// site (and only that one) proxies /terminal to it as a WebSocket.
	TerminalSocket string
}

type Manager struct {
	cfg Config
	run Runner

	http2Once   sync.Once
	http2Modern bool
}

func NewManager(cfg Config, run Runner) *Manager {
	if run == nil {
		run = execRunner{}
	}
	if cfg.StateDir == "" {
		cfg.StateDir = statedir.Default(cfg.DataDir) // development and tests; a real install passes --state-dir
	}
	return &Manager{cfg: cfg, run: run}
}

// panelSiteName is the reserved name of the panel's own site (see the panel's
// PanelDomain); it is the only site that serves the terminal.
const panelSiteName = "panel"

var siteTmpl = template.Must(template.New("site").Parse(`# Managed by CommyPanel. Do not edit; changes are overwritten.
server {
    listen 80;
    listen [::]:80;
    server_name {{.ServerNames}};

    location ^~ /.well-known/acme-challenge/ {
        allow all;
        root {{.Webroot}};
    }
{{if .CertDir}}
    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl{{if not .ModernHTTP2}} http2{{end}};
    listen [::]:443 ssl{{if not .ModernHTTP2}} http2{{end}};
{{if .ModernHTTP2}}    http2 on;
{{end}}    server_name {{.ServerNames}};

    ssl_certificate {{.CertDir}}/fullchain.pem;
    ssl_certificate_key {{.CertDir}}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
{{end}}
    # Shared by the app's location / and by locations added as custom directives.
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    set $commypanel_upstream http://127.0.0.1:{{.Port}};
{{if .Custom}}
    # Custom directives, edited in CommyPanel. Checked before they are written.
{{.Custom}}{{end}}{{if .TerminalSocket}}
    # Browser terminals: a WebSocket to the agent's terminal socket, which only
    # accepts a single-use ticket.
    location = /terminal {
        proxy_pass http://unix:{{.TerminalSocket}};
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 7200s;
        proxy_send_timeout 7200s;
        proxy_buffering off;
    }
{{end}}
    location / {
        proxy_pass http://127.0.0.1:{{.Port}};
    }
}
{{range .Redirects}}
server {
    listen 80;
    listen [::]:80;
    server_name {{.From}};

    location ^~ /.well-known/acme-challenge/ {
        root {{$.Webroot}};
    }

    location / {
        return 301 {{if $.CertDir}}https{{else}}http{{end}}://{{.To}}$request_uri;
    }
}
{{if $.CertDir}}
server {
    listen 443 ssl{{if not $.ModernHTTP2}} http2{{end}};
    listen [::]:443 ssl{{if not $.ModernHTTP2}} http2{{end}};
{{if $.ModernHTTP2}}    http2 on;
{{end}}    server_name {{.From}};

    ssl_certificate {{$.CertDir}}/fullchain.pem;
    ssl_certificate_key {{$.CertDir}}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        return 301 https://{{.To}}$request_uri;
    }
}
{{end}}{{end}}
`))

var defaultTmpl = template.Must(template.New("default").Parse(`# Managed by CommyPanel. Do not edit; changes are overwritten.
# Catches port-80 requests for hosts no app serves, so Let's Encrypt can
# validate a domain before its app is live. Everything else gets a 404.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    location /.well-known/acme-challenge/ {
        root {{.Webroot}};
    }

    location / {
        return 404;
    }
}
`))

// EnsureDefaultSite writes the catch-all port-80 site that serves ACME
// challenges. If nginx rejects it (for example because another default_server
// exists) the file is removed again and the error returned.
func (m *Manager) EnsureDefaultSite(ctx context.Context) error {
	var buf bytes.Buffer
	if err := defaultTmpl.Execute(&buf, struct{ Webroot string }{filepath.Join(m.cfg.StateDir, "acme-webroot")}); err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Join(m.cfg.StateDir, "acme-webroot"), 0o755); err != nil {
		return err
	}
	path := filepath.Join(m.cfg.SitesDir, "00-commypanel-default.conf")
	previous, readErr := os.ReadFile(path)
	if readErr == nil && bytes.Equal(previous, buf.Bytes()) {
		return nil
	}
	if err := writeAtomic(path, buf.Bytes()); err != nil {
		return err
	}
	if err := m.testAndReload(ctx); err != nil {
		m.restore(path, previous, readErr == nil)
		return err
	}
	return nil
}

// Render returns the nginx config for a validated site.
func (m *Manager) Render(s Site) (string, error) {
	if err := s.Validate(); err != nil {
		return "", err
	}
	custom, err := CheckCustom(s.Custom)
	if err != nil {
		return "", err
	}
	data := struct {
		ServerNames, Webroot, CertDir, Custom, TerminalSocket string
		Port                                                  int
		ModernHTTP2                                           bool
		Redirects                                             []Redirect
	}{
		ServerNames: strings.Join(s.served(), " "),
		Redirects:   s.Redirects,
		Webroot:     filepath.Join(m.cfg.StateDir, "acme-webroot"),
		Port:        s.UpstreamPort,
		Custom:      custom,
	}
	if s.Name == panelSiteName {
		data.TerminalSocket = m.cfg.TerminalSocket
	}
	if s.Certificate != "" {
		data.ModernHTTP2 = m.modernHTTP2() // only HTTPS sites use HTTP/2
	}
	if s.Certificate != "" {
		data.CertDir = filepath.Join(m.cfg.StateDir, "certs", s.Certificate)
	}
	var buf bytes.Buffer
	if err := siteTmpl.Execute(&buf, data); err != nil {
		return "", err
	}
	return buf.String(), nil
}

var nginxVersionRe = regexp.MustCompile(`nginx/(\d+)\.(\d+)\.(\d+)`)

// modernHTTP2 reports whether the installed nginx understands the standalone
// `http2 on;` directive (1.25.1 and later). Older versions, and any case where
// the version cannot be read, get `listen ... ssl http2;`, which every version
// accepts (newer ones only warn that it is deprecated).
func (m *Manager) modernHTTP2() bool {
	m.http2Once.Do(func() {
		if m.cfg.DryRun {
			m.http2Modern = true
			return
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		out, _ := m.run.Run(ctx, "nginx", "-v")
		m.http2Modern = versionAtLeast(string(out), 1, 25, 1)
	})
	return m.http2Modern
}

func versionAtLeast(output string, major, minor, patch int) bool {
	match := nginxVersionRe.FindStringSubmatch(output)
	if match == nil {
		return false
	}
	have := [3]int{}
	for i := range have {
		have[i], _ = strconv.Atoi(match[i+1])
	}
	want := [3]int{major, minor, patch}
	for i := range have {
		if have[i] != want[i] {
			return have[i] > want[i]
		}
	}
	return true
}

func (m *Manager) path(name string) string {
	return filepath.Join(m.cfg.SitesDir, "commypanel-"+name+".conf")
}

// Apply writes the site, validates the whole nginx config and reloads
// gracefully. If validation fails the previous config is restored.
func (m *Manager) Apply(ctx context.Context, s Site) error {
	conf, err := m.Render(s)
	if err != nil {
		return err
	}
	path := m.path(s.Name)
	previous, readErr := os.ReadFile(path)
	hadPrevious := readErr == nil

	if err := writeAtomic(path, []byte(conf)); err != nil {
		return fmt.Errorf("write site: %w", err)
	}
	if err := m.testAndReload(ctx); err != nil {
		m.restore(path, previous, hadPrevious)
		return err
	}
	slog.Info("nginx site applied", "site", s.Name, "dry_run", m.cfg.DryRun)
	return nil
}

// Remove deletes a site config and reloads nginx.
func (m *Manager) Remove(ctx context.Context, name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	path := m.path(name)
	previous, readErr := os.ReadFile(path)
	if errors.Is(readErr, os.ErrNotExist) {
		return nil
	}
	if err := os.Remove(path); err != nil {
		return fmt.Errorf("remove site: %w", err)
	}
	if err := m.testAndReload(ctx); err != nil {
		m.restore(path, previous, true)
		return err
	}
	slog.Info("nginx site removed", "site", name, "dry_run", m.cfg.DryRun)
	return nil
}

func (m *Manager) testAndReload(ctx context.Context) error {
	if m.cfg.DryRun {
		return nil
	}
	if out, err := m.run.Run(ctx, "nginx", "-t"); err != nil {
		return fmt.Errorf("nginx -t failed: %s", strings.TrimSpace(string(out)))
	}
	if out, err := m.run.Run(ctx, "nginx", "-s", "reload"); err != nil {
		return fmt.Errorf("nginx reload failed: %s", strings.TrimSpace(string(out)))
	}
	return nil
}

func (m *Manager) restore(path string, previous []byte, had bool) {
	if had {
		if err := writeAtomic(path, previous); err != nil {
			slog.Error("could not restore previous nginx config", "path", path, "err", err)
		}
		return
	}
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		slog.Error("could not remove rejected nginx config", "path", path, "err", err)
	}
}

func writeAtomic(path string, data []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".commypanel-*.tmp")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o644); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

// SiteFile describes one config the agent wrote, for the panel's Server page.
type SiteFile struct {
	Name     string   `json:"name"`
	Domains  []string `json:"domains"`
	HTTPS    bool     `json:"https"`
	Modified int64    `json:"modified"`
}

// Status is nginx as seen from the host.
type Status struct {
	Available    bool       `json:"available"`
	Running      bool       `json:"running"`
	Version      string     `json:"version"`
	ConfigOK     bool       `json:"config_ok"`
	ConfigOutput string     `json:"config_output"`
	Sites        []SiteFile `json:"sites"`
}

// Status reports whether nginx runs, its version, whether its configuration
// is valid, and the sites the agent manages.
func (m *Manager) Status(ctx context.Context) Status {
	st := Status{Sites: m.siteFiles()}
	if m.cfg.DryRun {
		st.ConfigOK = true
		st.ConfigOutput = "dry-run: nginx is not contacted"
		return st
	}

	out, err := m.run.Run(ctx, "nginx", "-v")
	if err != nil && len(out) == 0 {
		return st // not installed
	}
	st.Available = true
	if match := nginxVersionRe.FindString(string(out)); match != "" {
		st.Version = strings.TrimPrefix(match, "nginx/")
	}
	if state, _ := m.run.Run(ctx, "systemctl", "is-active", "nginx"); strings.TrimSpace(string(state)) == "active" {
		st.Running = true
	}
	st.ConfigOK, st.ConfigOutput = m.Test(ctx)

	return st
}

// Test runs `nginx -t` and returns its verdict and output.
func (m *Manager) Test(ctx context.Context) (bool, string) {
	if m.cfg.DryRun {
		return true, "dry-run: nginx is not contacted"
	}
	out, err := m.run.Run(ctx, "nginx", "-t")
	text := strings.TrimSpace(string(out))
	if len(text) > 4000 {
		text = text[:4000]
	}
	return err == nil, text
}

// Reload tests the configuration and reloads nginx gracefully.
func (m *Manager) Reload(ctx context.Context) error {
	return m.testAndReload(ctx)
}

var serverNameRe = regexp.MustCompile(`(?m)^\s*server_name\s+([^;]+);`)

// siteFiles lists the site configs the agent manages, with their domains
// read back from the files themselves.
func (m *Manager) siteFiles() []SiteFile {
	files, _ := filepath.Glob(filepath.Join(m.cfg.SitesDir, "commypanel-*.conf"))
	sites := make([]SiteFile, 0, len(files))
	for _, file := range files {
		raw, err := os.ReadFile(file)
		if err != nil {
			continue
		}
		site := SiteFile{
			Name:  strings.TrimSuffix(strings.TrimPrefix(filepath.Base(file), "commypanel-"), ".conf"),
			HTTPS: strings.Contains(string(raw), "ssl_certificate "),
		}
		if info, err := os.Stat(file); err == nil {
			site.Modified = info.ModTime().Unix()
		}
		seen := map[string]bool{}
		for _, match := range serverNameRe.FindAllStringSubmatch(string(raw), -1) {
			for _, name := range strings.Fields(match[1]) {
				if name != "_" && !seen[name] {
					seen[name] = true
					site.Domains = append(site.Domains, name)
				}
			}
		}
		sites = append(sites, site)
	}
	return sites
}
