// Package server exposes the agent's typed actions over HTTP on a Unix socket.
package server

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"strconv"

	"commypanel/agent/internal/certs"
	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/nginx"
	"commypanel/agent/internal/peercred"
	"commypanel/agent/internal/system"
	"commypanel/agent/internal/terminal"
	"commypanel/agent/internal/usage"
	"commypanel/agent/internal/validate"
	"commypanel/agent/internal/volumefs"
)

const maxBody = 64 << 10

// Nginx is the part of the nginx manager the server needs.
type Nginx interface {
	Apply(ctx context.Context, s nginx.Site) error
	Render(s nginx.Site) (string, error)
	Remove(ctx context.Context, name string) error
	Status(ctx context.Context) nginx.Status
	Test(ctx context.Context) (bool, string)
	Reload(ctx context.Context) error
}

// Docker is the part of the Docker manager the server needs.
type Docker interface {
	Run(ctx context.Context, s docker.ContainerSpec) error
	Stop(ctx context.Context, name string) error
	Start(ctx context.Context, name string) error
	Restart(ctx context.Context, name string) error
	Remove(ctx context.Context, name string) error
	List(ctx context.Context) ([]docker.ContainerInfo, error)
	Networks(ctx context.Context) ([]docker.NetworkInfo, error)
	Logs(ctx context.Context, name string, tail int) (string, error)
	ValidateBuild(s docker.BuildSpec) error
	Build(ctx context.Context, s docker.BuildSpec, onLine func(string)) error
	ValidateTask(s docker.TaskSpec) error
	RunTask(ctx context.Context, s docker.TaskSpec, onLine func(string)) (int, error)
	PruneImages(ctx context.Context) (uint64, error)
	RemoveImage(ctx context.Context, tag string) error
	RemoveVolumes(ctx context.Context, app, name string) error
	Info(ctx context.Context) (docker.Info, error)
	Ping(ctx context.Context) error
	ListImages(ctx context.Context) ([]docker.ImageInfo, error)
}

// Volumes is the read-only view into an app's persistent volumes.
type Volumes interface {
	List(ctx context.Context, app string) ([]volumefs.Volume, error)
	Dir(app, name, rel string) (volumefs.Listing, error)
	Preview(app, name, rel string) (volumefs.Preview, error)
	Open(app, name, rel string) (volumefs.Download, error)
}

// Terminal hands out the single-use tickets a browser needs to open a terminal.
type Terminal interface {
	Issue(container, origin string) (string, error)
}

// Certificates is the part of the certificate manager the server needs.
type Certificates interface {
	Issue(ctx context.Context, r certs.Request) (certs.Info, error)
	Info(name string) (*certs.Info, error)
	Remove(name string) error
}

// DockerUnit is the Docker daemon's systemd unit.
type DockerUnit interface {
	Diagnose(ctx context.Context) system.DockerDiagnosis
	Restart(ctx context.Context) error
}

// Usage is the resource sampler.
type Usage interface {
	Snapshot(history bool) usage.Snapshot
}

// Authorizer decides whether the caller of a request may use the agent.
type Authorizer func(r *http.Request) bool

type Options struct {
	Nginx     Nginx
	Docker    Docker
	Volumes   Volumes
	Terminal  Terminal
	Certs     Certificates
	Unit      DockerUnit
	Usage     Usage
	Authorize Authorizer
	Version   string
	DryRun    bool
	System    func() system.Status
	// Info gathers the host facts for the Server page.
	Info func(ctx context.Context) system.Info
}

type Server struct{ opts Options }

func New(opts Options) *Server { return &Server{opts: opts} }

// Handler returns the HTTP handler with authorization applied to every route.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /v1/status", s.status)
	mux.HandleFunc("GET /v1/system/info", s.systemInfo)
	mux.HandleFunc("GET /v1/system/usage", s.systemUsage)
	mux.HandleFunc("GET /v1/nginx/status", s.nginxStatus)
	mux.HandleFunc("POST /v1/nginx/test", s.nginxTest)
	mux.HandleFunc("POST /v1/nginx/reload", s.nginxReload)
	mux.HandleFunc("GET /v1/docker/info", s.dockerInfo)
	mux.HandleFunc("GET /v1/docker/diagnose", s.dockerDiagnose)
	mux.HandleFunc("POST /v1/docker/restart", s.dockerRestart)
	mux.HandleFunc("GET /v1/docker/images", s.listImages)
	mux.HandleFunc("PUT /v1/nginx/sites", s.applySite)
	mux.HandleFunc("POST /v1/nginx/render", s.renderSite)
	mux.HandleFunc("POST /v1/nginx/custom/check", s.checkCustom)
	mux.HandleFunc("DELETE /v1/nginx/sites/{name}", s.removeSite)
	mux.HandleFunc("POST /v1/certificates/issue", s.issueCertificate)
	mux.HandleFunc("GET /v1/certificates/{name}", s.certificateInfo)
	mux.HandleFunc("DELETE /v1/certificates/{name}", s.removeCertificate)
	mux.HandleFunc("POST /v1/docker/containers", s.runContainer)
	mux.HandleFunc("GET /v1/docker/containers", s.listContainers)
	mux.HandleFunc("POST /v1/docker/containers/{name}/stop", s.stopContainer)
	mux.HandleFunc("POST /v1/docker/containers/{name}/start", s.startContainer)
	mux.HandleFunc("POST /v1/docker/containers/{name}/restart", s.restartContainer)
	mux.HandleFunc("POST /v1/docker/tasks", s.runTask)
	mux.HandleFunc("DELETE /v1/docker/containers/{name}", s.removeContainer)
	mux.HandleFunc("GET /v1/docker/containers/{name}/logs", s.containerLogs)
	mux.HandleFunc("POST /v1/docker/images/build", s.buildImage)
	mux.HandleFunc("POST /v1/docker/images/prune", s.pruneImages)
	mux.HandleFunc("POST /v1/docker/images/remove", s.removeImage)
	mux.HandleFunc("GET /v1/docker/networks", s.networks)
	mux.HandleFunc("POST /v1/terminal/tickets", s.terminalTicket)
	mux.HandleFunc("POST /v1/volumes/remove", s.removeVolumes)
	mux.HandleFunc("GET /v1/volumes/{app}", s.listVolumes)
	mux.HandleFunc("GET /v1/volumes/{app}/{name}/dir", s.volumeDir)
	mux.HandleFunc("GET /v1/volumes/{app}/{name}/file", s.volumeFile)
	mux.HandleFunc("GET /v1/volumes/{app}/{name}/download", s.volumeDownload)

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if s.opts.Authorize == nil || !s.opts.Authorize(r) {
			writeJSON(w, http.StatusForbidden, map[string]string{"error": "forbidden"})
			return
		}
		mux.ServeHTTP(w, r)
	})
}

type credKey struct{}

// ConnContext stores the peer credentials of each new connection so the
// authorizer can read them from the request context.
func ConnContext(ctx context.Context, c net.Conn) context.Context {
	cred, err := peercred.Get(c)
	if err != nil {
		return ctx
	}
	return context.WithValue(ctx, credKey{}, cred)
}

// AllowUIDs authorizes callers whose peer UID is in the allow-list.
func AllowUIDs(uids ...uint32) Authorizer {
	allowed := make(map[uint32]bool, len(uids))
	for _, u := range uids {
		allowed[u] = true
	}
	return func(r *http.Request) bool {
		cred, ok := r.Context().Value(credKey{}).(peercred.Cred)
		return ok && allowed[cred.UID]
	}
}

// AllowAll is only for dry-run development on platforms without peer credentials.
func AllowAll() Authorizer { return func(*http.Request) bool { return true } }

func (s *Server) status(w http.ResponseWriter, r *http.Request) {
	var sys system.Status
	if s.opts.System != nil {
		sys = s.opts.System()
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"version":          s.opts.Version,
		"dry_run":          s.opts.DryRun,
		"nginx_available":  sys.NginxAvailable,
		"docker_available": s.opts.Docker != nil && s.opts.Docker.Ping(r.Context()) == nil,
		"disk_free_bytes":  sys.DiskFreeBytes,
	})
}

// dockerDiagnose says whether the daemon answers and, from systemd, why not.
func (s *Server) dockerDiagnose(w http.ResponseWriter, r *http.Request) {
	out := map[string]any{"available": true}
	if err := s.opts.Docker.Ping(r.Context()); err != nil {
		out["available"] = false
		out["error"] = err.Error()
	}
	if s.opts.Unit != nil {
		d := s.opts.Unit.Diagnose(r.Context())
		out["service"], out["socket"], out["journal"] = d.Service, d.Socket, d.Journal
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) dockerRestart(w http.ResponseWriter, r *http.Request) {
	if s.opts.Unit == nil {
		writeJSON(w, http.StatusNotImplemented, map[string]string{"error": "restarting Docker is not available"})
		return
	}
	s.respond(w, s.opts.Unit.Restart(r.Context()))
}

func (s *Server) systemInfo(w http.ResponseWriter, r *http.Request) {
	var info system.Info
	if s.opts.Info != nil {
		info = s.opts.Info(r.Context())
	}
	writeJSON(w, http.StatusOK, map[string]any{"version": s.opts.Version, "dry_run": s.opts.DryRun, "info": info})
}

// systemUsage returns the latest CPU, memory, disk and network readings of the
// host and its containers; ?history=1 adds the last few minutes.
func (s *Server) systemUsage(w http.ResponseWriter, r *http.Request) {
	if s.opts.Usage == nil {
		writeJSON(w, http.StatusNotImplemented, map[string]string{"error": "resource usage is not available"})
		return
	}
	writeJSON(w, http.StatusOK, s.opts.Usage.Snapshot(r.URL.Query().Get("history") == "1"))
}

func (s *Server) nginxStatus(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, s.opts.Nginx.Status(r.Context()))
}

func (s *Server) nginxTest(w http.ResponseWriter, r *http.Request) {
	ok, output := s.opts.Nginx.Test(r.Context())
	writeJSON(w, http.StatusOK, map[string]any{"ok": ok, "output": output})
}

func (s *Server) nginxReload(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Nginx.Reload(r.Context()))
}

func (s *Server) dockerInfo(w http.ResponseWriter, r *http.Request) {
	info, err := s.opts.Docker.Info(r.Context())
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, info)
}

func (s *Server) listImages(w http.ResponseWriter, r *http.Request) {
	images, err := s.opts.Docker.ListImages(r.Context())
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"images": images})
}

func (s *Server) applySite(w http.ResponseWriter, r *http.Request) {
	var site nginx.Site
	if !decode(w, r, &site) {
		return
	}
	s.respond(w, s.opts.Nginx.Apply(r.Context(), site))
}

// renderSite returns the config a site would get, without writing anything,
// so the panel can show the generated file next to the custom directives.
func (s *Server) renderSite(w http.ResponseWriter, r *http.Request) {
	var site nginx.Site
	if !decode(w, r, &site) {
		return
	}
	conf, err := s.opts.Nginx.Render(site)
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"config": conf})
}

// checkCustom validates custom directives on their own (no site needed), so a
// refusal can be explained before anything is saved.
func (s *Server) checkCustom(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Custom string `json:"custom"`
	}
	if !decode(w, r, &body) {
		return
	}
	canonical, err := nginx.CheckCustom(body.Custom)
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "custom": canonical})
}

func (s *Server) removeSite(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Nginx.Remove(r.Context(), r.PathValue("name")))
}

func (s *Server) issueCertificate(w http.ResponseWriter, r *http.Request) {
	var req certs.Request
	if !decode(w, r, &req) {
		return
	}
	info, err := s.opts.Certs.Issue(r.Context(), req)
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, info)
}

func (s *Server) certificateInfo(w http.ResponseWriter, r *http.Request) {
	info, err := s.opts.Certs.Info(r.PathValue("name"))
	if err != nil {
		s.respond(w, err)
		return
	}
	if info == nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "no certificate with that name"})
		return
	}
	writeJSON(w, http.StatusOK, info)
}

func (s *Server) removeCertificate(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Certs.Remove(r.PathValue("name")))
}

func (s *Server) runContainer(w http.ResponseWriter, r *http.Request) {
	var spec docker.ContainerSpec
	if !decode(w, r, &spec) {
		return
	}
	s.respond(w, s.opts.Docker.Run(r.Context(), spec))
}

func (s *Server) stopContainer(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Docker.Stop(r.Context(), r.PathValue("name")))
}

func (s *Server) startContainer(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Docker.Start(r.Context(), r.PathValue("name")))
}

func (s *Server) restartContainer(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Docker.Restart(r.Context(), r.PathValue("name")))
}

func (s *Server) removeContainer(w http.ResponseWriter, r *http.Request) {
	s.respond(w, s.opts.Docker.Remove(r.Context(), r.PathValue("name")))
}

func (s *Server) listContainers(w http.ResponseWriter, r *http.Request) {
	list, err := s.opts.Docker.List(r.Context())
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"containers": list})
}

// terminalTicket registers a one-use ticket for a shell in one container. The
// panel has already checked the user's permission and password; the agent
// checks that the names are well formed and the container is a managed one
// when the shell is opened.
func (s *Server) terminalTicket(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Container string `json:"container"`
		Origin    string `json:"origin"`
	}
	if !decode(w, r, &body) {
		return
	}
	if s.opts.Terminal == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "terminals are not enabled on this agent"})
		return
	}
	ticket, err := s.opts.Terminal.Issue(body.Container, body.Origin)
	if errors.Is(err, terminal.ErrTooManyTickets) {
		writeJSON(w, http.StatusTooManyRequests, map[string]string{"error": "too many terminals are being opened; try again in a moment"})
		return
	}
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ticket": ticket, "expires_in": int(terminal.TicketTTL.Seconds())})
}

func (s *Server) networks(w http.ResponseWriter, r *http.Request) {
	list, err := s.opts.Docker.Networks(r.Context())
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"networks": list})
}

func (s *Server) containerLogs(w http.ResponseWriter, r *http.Request) {
	tail := 200
	if v := r.URL.Query().Get("tail"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid tail"})
			return
		}
		tail = n
	}
	out, err := s.opts.Docker.Logs(r.Context(), r.PathValue("name"), tail)
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"logs": out})
}

func (s *Server) removeImage(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Tag string `json:"tag"`
	}
	if !decode(w, r, &body) {
		return
	}
	s.respond(w, s.opts.Docker.RemoveImage(r.Context(), body.Tag))
}

func (s *Server) listVolumes(w http.ResponseWriter, r *http.Request) {
	list, err := s.opts.Volumes.List(r.Context(), r.PathValue("app"))
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"volumes": list})
}

func (s *Server) volumeDir(w http.ResponseWriter, r *http.Request) {
	l, err := s.opts.Volumes.Dir(r.PathValue("app"), r.PathValue("name"), r.URL.Query().Get("path"))
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, l)
}

func (s *Server) volumeFile(w http.ResponseWriter, r *http.Request) {
	p, err := s.opts.Volumes.Preview(r.PathValue("app"), r.PathValue("name"), r.URL.Query().Get("path"))
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, p)
}

// volumeDownload streams one plain file as raw bytes.
func (s *Server) volumeDownload(w http.ResponseWriter, r *http.Request) {
	d, err := s.opts.Volumes.Open(r.PathValue("app"), r.PathValue("name"), r.URL.Query().Get("path"))
	if err != nil {
		s.respond(w, err)
		return
	}
	defer d.File.Close()
	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("Content-Length", strconv.FormatInt(d.Size, 10))
	w.WriteHeader(http.StatusOK)
	_, _ = io.Copy(w, io.LimitReader(d.File, d.Size))
}

func (s *Server) removeVolumes(w http.ResponseWriter, r *http.Request) {
	var body struct {
		App  string `json:"app"`
		Name string `json:"name"`
	}
	if !decode(w, r, &body) {
		return
	}
	s.respond(w, s.opts.Docker.RemoveVolumes(r.Context(), body.App, body.Name))
}

func (s *Server) pruneImages(w http.ResponseWriter, r *http.Request) {
	reclaimed, err := s.opts.Docker.PruneImages(r.Context())
	if err != nil {
		s.respond(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "space_reclaimed": reclaimed})
}

// buildImage validates first (plain JSON error), then streams build output as
// newline-delimited JSON: {"log":"..."} lines and a final {"ok":true} or
// {"error":"..."}.
func (s *Server) buildImage(w http.ResponseWriter, r *http.Request) {
	var spec docker.BuildSpec
	if !decode(w, r, &spec) {
		return
	}
	if err := s.opts.Docker.ValidateBuild(spec); err != nil {
		s.respond(w, err)
		return
	}

	send := stream(w)
	err := s.opts.Docker.Build(r.Context(), spec, func(line string) { send(map[string]string{"log": line}) })
	if err != nil {
		slog.Error("build failed", "tag", spec.Tag, "err", err)
		send(map[string]string{"error": err.Error()})
		return
	}
	send(map[string]bool{"ok": true})
}

// runTask runs a one-off command to completion. Like buildImage it validates
// first, then streams {"log":"..."} lines and ends with {"exit_code":N} or
// {"error":"..."}. A non-zero exit code is a result, not an error.
func (s *Server) runTask(w http.ResponseWriter, r *http.Request) {
	var spec docker.TaskSpec
	if !decode(w, r, &spec) {
		return
	}
	if err := s.opts.Docker.ValidateTask(spec); err != nil {
		s.respond(w, err)
		return
	}

	send := stream(w)
	code, err := s.opts.Docker.RunTask(r.Context(), spec, func(line string) { send(map[string]string{"log": line}) })
	if err != nil {
		slog.Error("task failed", "name", spec.Name, "err", err)
		send(map[string]string{"error": err.Error()})
		return
	}
	send(map[string]int{"exit_code": code})
}

// stream starts a newline-delimited JSON response and returns a function
// that writes and flushes one message.
func stream(w http.ResponseWriter) func(v any) {
	w.Header().Set("Content-Type", "application/x-ndjson")
	w.WriteHeader(http.StatusOK)
	enc := json.NewEncoder(w)
	flusher, _ := w.(http.Flusher)
	return func(v any) {
		_ = enc.Encode(v)
		if flusher != nil {
			flusher.Flush()
		}
	}
}

func decode(w http.ResponseWriter, r *http.Request, v any) bool {
	dec := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxBody))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid request body"})
		return false
	}
	return true
}

func (s *Server) respond(w http.ResponseWriter, err error) {
	var input *validate.InputError
	switch {
	case err == nil:
		writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
	case errors.As(err, &input):
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": input.Msg})
	case errors.Is(err, docker.ErrUnavailable):
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": "docker is not available"})
	default:
		slog.Error("action failed", "err", err)
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
