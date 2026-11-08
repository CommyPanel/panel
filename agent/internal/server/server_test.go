package server

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"commypanel/agent/internal/certs"
	"commypanel/agent/internal/dbtool"
	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/nginx"
	"commypanel/agent/internal/system"
	"commypanel/agent/internal/terminal"
	"commypanel/agent/internal/traffic"
	"commypanel/agent/internal/usage"
	"commypanel/agent/internal/validate"
	"commypanel/agent/internal/volumefs"
)

type fakeNginx struct {
	applied  []nginx.Site
	removed  []string
	reloaded int
	err      error
}

func (f *fakeNginx) Apply(_ context.Context, s nginx.Site) error {
	if err := s.Validate(); err != nil {
		return err
	}
	f.applied = append(f.applied, s)
	return f.err
}

func (f *fakeNginx) Render(s nginx.Site) (string, error) {
	if err := s.Validate(); err != nil {
		return "", err
	}
	return "# rendered " + s.Name + "\n" + s.Custom, nil
}

func (f *fakeNginx) Status(context.Context) nginx.Status {
	return nginx.Status{Available: true, Running: true, Version: "1.26.0", ConfigOK: true, ConfigOutput: "ok", Sites: []nginx.SiteFile{{Name: "demo", Domains: []string{"demo.example.com"}}}}
}

func (f *fakeNginx) Test(context.Context) (bool, string) { return f.err == nil, "syntax is ok" }

func (f *fakeNginx) Reload(context.Context) error {
	f.reloaded++
	return f.err
}

func (f *fakeNginx) Remove(_ context.Context, name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	f.removed = append(f.removed, name)
	return f.err
}

func do(t *testing.T, s *Server, method, path, body string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(method, path, strings.NewReader(body))
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

func newServer(f *fakeNginx) *Server {
	return New(Options{Nginx: f, Authorize: AllowAll(), Version: "test"})
}

const validSite = `{"name":"demo","domains":["demo.example.com"],"upstream_port":20001}`

func TestRefusesWithoutAuthorizer(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}})
	if rec := do(t, s, "GET", "/v1/status", ""); rec.Code != http.StatusForbidden {
		t.Errorf("code = %d, want 403", rec.Code)
	}
}

func TestRefusesUnknownCaller(t *testing.T) {
	// No peer credentials in the request context: AllowUIDs must deny.
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowUIDs(0, 1000)})
	if rec := do(t, s, "PUT", "/v1/nginx/sites", validSite); rec.Code != http.StatusForbidden {
		t.Errorf("code = %d, want 403", rec.Code)
	}
}

func TestStatus(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll(), Version: "test",
		System: func() system.Status { return system.Status{NginxAvailable: true, DiskFreeBytes: 42} }})
	rec := do(t, s, "GET", "/v1/status", "")
	body := rec.Body.String()
	if rec.Code != 200 || !strings.Contains(body, `"version":"test"`) ||
		!strings.Contains(body, `"nginx_available":true`) || !strings.Contains(body, `"disk_free_bytes":42`) {
		t.Errorf("code=%d body=%s", rec.Code, rec.Body)
	}
}

func TestApplySite(t *testing.T) {
	f := &fakeNginx{}
	rec := do(t, newServer(f), "PUT", "/v1/nginx/sites", validSite)
	if rec.Code != 200 || len(f.applied) != 1 || f.applied[0].UpstreamPort != 20001 {
		t.Errorf("code=%d applied=%v", rec.Code, f.applied)
	}
}

func TestInvalidInputIs400(t *testing.T) {
	for name, body := range map[string]string{
		"bad domain":    `{"name":"demo","domains":["a.com;"],"upstream_port":20001}`,
		"bad name":      `{"name":"../x","domains":["a.example.com"],"upstream_port":20001}`,
		"unknown field": `{"name":"demo","domains":["a.example.com"],"upstream_port":20001,"raw_config":"x"}`,
		"not json":      `nope`,
	} {
		if rec := do(t, newServer(&fakeNginx{}), "PUT", "/v1/nginx/sites", body); rec.Code != http.StatusBadRequest {
			t.Errorf("%s: code = %d, want 400", name, rec.Code)
		}
	}
}

func TestOversizedBodyRejected(t *testing.T) {
	body := `{"name":"demo","domains":["a.example.com"],"upstream_port":20001,"certificate":"` + strings.Repeat("a", 70<<10) + `"}`
	if rec := do(t, newServer(&fakeNginx{}), "PUT", "/v1/nginx/sites", body); rec.Code != http.StatusBadRequest {
		t.Errorf("code = %d, want 400", rec.Code)
	}
}

func TestHostFailureIs500(t *testing.T) {
	f := &fakeNginx{err: errors.New("nginx -t failed: boom")}
	rec := do(t, newServer(f), "PUT", "/v1/nginx/sites", validSite)
	if rec.Code != 500 || !strings.Contains(rec.Body.String(), "boom") {
		t.Errorf("code=%d body=%s", rec.Code, rec.Body)
	}
}

func TestRemoveSite(t *testing.T) {
	f := &fakeNginx{}
	if rec := do(t, newServer(f), "DELETE", "/v1/nginx/sites/demo", ""); rec.Code != 200 || f.removed[0] != "demo" {
		t.Errorf("code=%d removed=%v", rec.Code, f.removed)
	}
	if rec := do(t, newServer(f), "DELETE", "/v1/nginx/sites/BAD", ""); rec.Code != 400 {
		t.Errorf("code = %d, want 400", rec.Code)
	}
}

type fakeDocker struct {
	ran             []docker.ContainerSpec
	stopped         []string
	started         []string
	restarted       []string
	removed         []string
	tasks           []docker.TaskSpec
	taskCode        int
	err             error
	buildErr        error
	buildLogs       []string
	removedImages   []string
	removedVolumes  []string
	removedNetworks []string
	calls           []string
	notReady        bool
}

func (f *fakeDocker) Run(_ context.Context, s docker.ContainerSpec) error {
	if f.err != nil {
		return f.err
	}
	if s.Name == "" {
		return &validate.InputError{Msg: "invalid name"}
	}
	f.ran = append(f.ran, s)
	return nil
}
func (f *fakeDocker) Stop(_ context.Context, n string) error {
	f.stopped = append(f.stopped, n)
	return f.err
}
func (f *fakeDocker) Start(_ context.Context, n string) error {
	f.started = append(f.started, n)
	return f.err
}
func (f *fakeDocker) Restart(_ context.Context, n string) error {
	f.restarted = append(f.restarted, n)
	return f.err
}
func (f *fakeDocker) ValidateTask(s docker.TaskSpec) error {
	if len(s.Command) == 0 {
		return &validate.InputError{Msg: "command is required"}
	}
	return nil
}
func (f *fakeDocker) RunTask(_ context.Context, s docker.TaskSpec, onLine func(string)) (int, error) {
	f.tasks = append(f.tasks, s)
	for _, l := range f.buildLogs {
		onLine(l)
	}
	return f.taskCode, f.buildErr
}
func (f *fakeDocker) Remove(_ context.Context, n string) error {
	f.removed = append(f.removed, n)
	return f.err
}
func (f *fakeDocker) List(context.Context) ([]docker.ContainerInfo, error) {
	return []docker.ContainerInfo{{Name: "demo-1", State: "running"}}, f.err
}
func (f *fakeDocker) Logs(_ context.Context, n string, tail int) (string, error) {
	return "tail=" + strconv.Itoa(tail), f.err
}
func (f *fakeDocker) ValidateBuild(s docker.BuildSpec) error {
	if !strings.HasPrefix(s.Tag, "commypanel/") {
		return &validate.InputError{Msg: "bad tag"}
	}
	return nil
}
func (f *fakeDocker) Build(_ context.Context, _ docker.BuildSpec, onLine func(string)) error {
	for _, l := range f.buildLogs {
		onLine(l)
	}
	return f.buildErr
}
func (f *fakeDocker) PruneImages(context.Context) (uint64, error) { return 99, f.err }
func (f *fakeDocker) Info(context.Context) (docker.Info, error) {
	return docker.Info{Available: true, ServerVersion: "27.0.1", Containers: 3, Running: 2}, f.err
}

func (f *fakeDocker) Ping(context.Context) error { return f.err }

func (f *fakeDocker) ListImages(context.Context) ([]docker.ImageInfo, error) {
	return []docker.ImageInfo{{Tags: []string{"commypanel/demo:1"}, Size: 1234}}, f.err
}

func (f *fakeDocker) RemoveImage(_ context.Context, tag string) error {
	if !strings.HasPrefix(tag, "commypanel/") {
		return &validate.InputError{Msg: "only commypanel/ images can be removed"}
	}
	f.removedImages = append(f.removedImages, tag)
	return f.err
}

func (f *fakeDocker) Networks(context.Context) ([]docker.NetworkInfo, error) {
	return []docker.NetworkInfo{{Name: "commypanel", Driver: "bridge", Subnet: "172.20.0.0/16", Containers: []docker.NetworkMember{{Name: "shop-1", IP: "172.20.0.2"}}}}, f.err
}

func (f *fakeDocker) RemoveNetwork(_ context.Context, name string) error {
	if err := validate.Network(name); err != nil {
		return err
	}
	f.removedNetworks = append(f.removedNetworks, name)
	return f.err
}

func (f *fakeDocker) PullImage(_ context.Context, image string) error {
	if err := validate.DatabaseImage(image); err != nil {
		return err
	}
	f.calls = append(f.calls, "pull "+image)
	return f.err
}

func (f *fakeDocker) DatabaseReady(context.Context, docker.DatabaseTarget) (bool, error) {
	return !f.notReady, f.err
}

func (f *fakeDocker) CreateDatabase(_ context.Context, t docker.DatabaseTarget, db, user, _ string) error {
	if err := validate.DatabaseIdent(db); err != nil {
		return err
	}
	f.calls = append(f.calls, "create "+t.Container+" "+db+" "+user)
	return f.err
}

func (f *fakeDocker) DropDatabase(_ context.Context, t docker.DatabaseTarget, db, user string) error {
	f.calls = append(f.calls, "drop "+t.Container+" "+db+" "+user)
	return f.err
}

func (f *fakeDocker) SetDatabasePassword(_ context.Context, t docker.DatabaseTarget, user, _ string) error {
	f.calls = append(f.calls, "password "+t.Container+" "+user)
	return f.err
}

func (f *fakeDocker) ScratchDir() (string, error) { return os.TempDir(), nil }

func (f *fakeDocker) RegisterDump(sum string) error {
	f.calls = append(f.calls, "register "+sum)
	return f.err
}

func (f *fakeDocker) RestoreDatabase(_ context.Context, t docker.DatabaseTarget, db, user, server, file string) error {
	f.calls = append(f.calls, "restore "+t.Container+" "+db+" "+user+" "+server+" "+file)
	return f.err
}

func (f *fakeDocker) ReplaceDatabase(_ context.Context, t docker.DatabaseTarget, db, user, server, file string) error {
	f.calls = append(f.calls, "replace "+t.Container+" "+db+" "+user+" "+server+" "+file)
	return f.err
}

func (f *fakeDocker) RegisterVolumeArchive(sum string) error {
	f.calls = append(f.calls, "register-volume "+sum)
	return f.err
}

func (f *fakeDocker) RestoreVolume(_ context.Context, app, volume, archive string) (volumefs.Summary, error) {
	f.calls = append(f.calls, "restore-volume "+app+" "+volume+" "+archive)
	return volumefs.Summary{Files: 3, Bytes: 42}, f.err
}

func (f *fakeDocker) DumpDatabase(_ context.Context, t docker.DatabaseTarget, db string) (string, error) {
	if f.err != nil {
		return "", f.err
	}
	if err := validate.DatabaseIdent(db); err != nil {
		return "", err
	}
	file, err := os.CreateTemp("", "fakedump")
	if err != nil {
		return "", err
	}
	file.WriteString("-- dump of " + db + " from " + t.Container + "\n")
	file.Close()
	return file.Name(), nil
}

func (f *fakeDocker) RemoveDatabaseData(_ context.Context, server string) error {
	f.calls = append(f.calls, "remove-data "+server)
	return f.err
}

func (f *fakeDocker) ConnectNetwork(_ context.Context, container, network string, aliases []string) error {
	if err := validate.LinkNetwork(network); err != nil {
		return err
	}
	f.calls = append(f.calls, "connect "+container+" "+network+" "+strings.Join(aliases, ","))
	return f.err
}

func (f *fakeDocker) DisconnectNetwork(_ context.Context, container, network string) error {
	f.calls = append(f.calls, "disconnect "+container+" "+network)
	return f.err
}

func (f *fakeDocker) PruneLinkNetworks(context.Context) ([]string, error) {
	return []string{"commypanel-link-d1"}, f.err
}

func (f *fakeDocker) RemoveVolumes(_ context.Context, app, name string) error {
	if err := validate.Name(app); err != nil {
		return err
	}
	f.removedVolumes = append(f.removedVolumes, app+"/"+name)
	return f.err
}

func newDockerServer(f *fakeDocker) *Server {
	return New(Options{Nginx: &fakeNginx{}, Docker: f, Authorize: AllowAll()})
}

func TestRunContainerRoute(t *testing.T) {
	f := &fakeDocker{}
	rec := do(t, newDockerServer(f), "POST", "/v1/docker/containers",
		`{"name":"demo-1","image":"commypanel/demo:r1","host_port":20001,"container_port":8080,"env":{"A":"1"}}`)
	if rec.Code != 200 || len(f.ran) != 1 || f.ran[0].HostPort != 20001 || f.ran[0].Env["A"] != "1" {
		t.Errorf("code=%d ran=%v body=%s", rec.Code, f.ran, rec.Body)
	}
}

func TestContainerRoutesRejectUnknownFields(t *testing.T) {
	// A caller must not be able to smuggle in settings like privileged or binds.
	for _, field := range []string{`"privileged":true`, `"binds":["/:/host"]`, `"cap_add":["SYS_ADMIN"]`, `"network_mode":"host"`} {
		f := &fakeDocker{}
		rec := do(t, newDockerServer(f), "POST", "/v1/docker/containers",
			`{"name":"demo-1","image":"commypanel/demo:r1",`+field+`}`)
		if rec.Code != http.StatusBadRequest || len(f.ran) != 0 {
			t.Errorf("%s: code=%d ran=%v", field, rec.Code, f.ran)
		}
	}
}

func TestDockerUnavailableIs503(t *testing.T) {
	f := &fakeDocker{err: fmt.Errorf("%w: dial", docker.ErrUnavailable)}
	rec := do(t, newDockerServer(f), "POST", "/v1/docker/containers/demo-1/stop", "")
	if rec.Code != http.StatusServiceUnavailable {
		t.Errorf("code = %d, want 503", rec.Code)
	}
}

func TestStopRemoveListLogsPrune(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	if rec := do(t, s, "POST", "/v1/docker/containers/demo-1/stop", ""); rec.Code != 200 || f.stopped[0] != "demo-1" {
		t.Errorf("stop: %d %v", rec.Code, f.stopped)
	}
	if rec := do(t, s, "DELETE", "/v1/docker/containers/demo-1", ""); rec.Code != 200 || f.removed[0] != "demo-1" {
		t.Errorf("remove: %d %v", rec.Code, f.removed)
	}
	if rec := do(t, s, "GET", "/v1/docker/containers", ""); !strings.Contains(rec.Body.String(), `"demo-1"`) {
		t.Errorf("list: %s", rec.Body)
	}
	if rec := do(t, s, "GET", "/v1/docker/containers/demo-1/logs?tail=50", ""); !strings.Contains(rec.Body.String(), "tail=50") {
		t.Errorf("logs: %s", rec.Body)
	}
	if rec := do(t, s, "GET", "/v1/docker/containers/demo-1/logs?tail=abc", ""); rec.Code != 400 {
		t.Errorf("bad tail: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/docker/images/prune", ""); !strings.Contains(rec.Body.String(), `"space_reclaimed":99`) {
		t.Errorf("prune: %s", rec.Body)
	}
}

func TestBuildStreamsNDJSON(t *testing.T) {
	f := &fakeDocker{buildLogs: []string{"Step 1", "Step 2"}}
	rec := do(t, newDockerServer(f), "POST", "/v1/docker/images/build", `{"tag":"commypanel/demo:r1","context_path":"/x"}`)
	if rec.Code != 200 || rec.Header().Get("Content-Type") != "application/x-ndjson" {
		t.Fatalf("code=%d type=%s", rec.Code, rec.Header().Get("Content-Type"))
	}
	lines := strings.Split(strings.TrimSpace(rec.Body.String()), "\n")
	want := []string{`{"log":"Step 1"}`, `{"log":"Step 2"}`, `{"ok":true}`}
	if strings.Join(lines, "|") != strings.Join(want, "|") {
		t.Errorf("lines = %v", lines)
	}
}

func TestBuildFailureAndValidation(t *testing.T) {
	f := &fakeDocker{buildErr: errors.New("build failed: boom")}
	rec := do(t, newDockerServer(f), "POST", "/v1/docker/images/build", `{"tag":"commypanel/demo:r1","context_path":"/x"}`)
	if !strings.Contains(rec.Body.String(), `{"error":"build failed: boom"}`) {
		t.Errorf("body = %s", rec.Body)
	}
	rec = do(t, newDockerServer(&fakeDocker{}), "POST", "/v1/docker/images/build", `{"tag":"library/alpine","context_path":"/x"}`)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("invalid build: code = %d, want 400", rec.Code)
	}
}

func TestStartAndRestartRoutes(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	if rec := do(t, s, "POST", "/v1/docker/containers/demo-1/start", ""); rec.Code != 200 || len(f.started) != 1 || f.started[0] != "demo-1" {
		t.Errorf("start: %d %v", rec.Code, f.started)
	}
	if rec := do(t, s, "POST", "/v1/docker/containers/demo-1/restart", ""); rec.Code != 200 || len(f.restarted) != 1 {
		t.Errorf("restart: %d %v", rec.Code, f.restarted)
	}
}

func TestTaskStreamsOutputAndExitCode(t *testing.T) {
	f := &fakeDocker{buildLogs: []string{"migrating", "done"}, taskCode: 3}
	rec := do(t, newDockerServer(f), "POST", "/v1/docker/tasks",
		`{"name":"demo-1-release","image":"commypanel/demo:1","command":["php","artisan","migrate"],"timeout_seconds":60}`)
	lines := strings.Split(strings.TrimSpace(rec.Body.String()), "\n")
	want := []string{`{"log":"migrating"}`, `{"log":"done"}`, `{"exit_code":3}`}
	if rec.Code != 200 || strings.Join(lines, "|") != strings.Join(want, "|") {
		t.Fatalf("code=%d lines=%v", rec.Code, lines)
	}
	if got := f.tasks[0].Command; len(got) != 3 || got[2] != "migrate" {
		t.Errorf("command = %v", got)
	}

	rec = do(t, newDockerServer(&fakeDocker{}), "POST", "/v1/docker/tasks", `{"name":"demo-1-release","image":"commypanel/demo:1","command":[]}`)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("invalid task: code = %d, want 400", rec.Code)
	}

	rec = do(t, newDockerServer(&fakeDocker{buildErr: errors.New("the command did not finish within 60 seconds")}), "POST", "/v1/docker/tasks",
		`{"name":"demo-1-release","image":"commypanel/demo:1","command":["sleep","100"]}`)
	if !strings.Contains(rec.Body.String(), `{"error":"the command did not finish within 60 seconds"}`) {
		t.Errorf("timeout body = %s", rec.Body)
	}
}

func TestRemoveImageRoute(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	if rec := do(t, s, "POST", "/v1/docker/images/remove", `{"tag":"commypanel/demo:1"}`); rec.Code != 200 || f.removedImages[0] != "commypanel/demo:1" {
		t.Errorf("remove: %d %v", rec.Code, f.removedImages)
	}
	if rec := do(t, s, "POST", "/v1/docker/images/remove", `{"tag":"library/alpine"}`); rec.Code != 400 {
		t.Errorf("foreign image: %d, want 400", rec.Code)
	}
}

func TestNetworksRoute(t *testing.T) {
	s := newDockerServer(&fakeDocker{})
	rec := do(t, s, "GET", "/v1/docker/networks", "")
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"name":"commypanel"`) || !strings.Contains(rec.Body.String(), `"ip":"172.20.0.2"`) {
		t.Errorf("networks: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, newDockerServer(&fakeDocker{err: docker.ErrUnavailable}), "GET", "/v1/docker/networks", ""); rec.Code != 503 {
		t.Errorf("docker down: %d, want 503", rec.Code)
	}
}

func TestRemoveNetworkRoute(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	if rec := do(t, s, "POST", "/v1/docker/networks/remove", `{"name":"commypanel-shop"}`); rec.Code != 200 || f.removedNetworks[0] != "commypanel-shop" {
		t.Errorf("remove: %d %v", rec.Code, f.removedNetworks)
	}
	for _, bad := range []string{`{"name":"host"}`, `{"name":"bridge"}`, `{"name":""}`, `{"name":"commypanel-shop","x":1}`} {
		if rec := do(t, s, "POST", "/v1/docker/networks/remove", bad); rec.Code != 400 {
			t.Errorf("%s: %d, want 400", bad, rec.Code)
		}
	}
}

func TestRemoveVolumesRoute(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	if rec := do(t, s, "POST", "/v1/volumes/remove", `{"app":"shop","name":"uploads"}`); rec.Code != 200 || f.removedVolumes[0] != "shop/uploads" {
		t.Errorf("remove one: %d %v", rec.Code, f.removedVolumes)
	}
	if rec := do(t, s, "POST", "/v1/volumes/remove", `{"app":"shop"}`); rec.Code != 200 || f.removedVolumes[1] != "shop/" {
		t.Errorf("remove all: %d %v", rec.Code, f.removedVolumes)
	}
	if rec := do(t, s, "POST", "/v1/volumes/remove", `{"app":"../etc"}`); rec.Code != 400 {
		t.Errorf("bad app: %d, want 400", rec.Code)
	}
}

type fakeCerts struct {
	issued  []certs.Request
	removed []string
	have    *certs.Info
	err     error
}

func (f *fakeCerts) Issue(_ context.Context, r certs.Request) (certs.Info, error) {
	if !r.AgreeTOS {
		return certs.Info{}, &validate.InputError{Msg: "agree_tos required"}
	}
	f.issued = append(f.issued, r)
	return certs.Info{Name: r.Name, Domains: r.Domains, Serial: "abc"}, f.err
}
func (f *fakeCerts) Info(name string) (*certs.Info, error) { return f.have, f.err }
func (f *fakeCerts) Remove(name string) error {
	f.removed = append(f.removed, name)
	return f.err
}

func newCertServer(f *fakeCerts) *Server {
	return New(Options{Nginx: &fakeNginx{}, Certs: f, Authorize: AllowAll()})
}

func TestIssueCertificateRoute(t *testing.T) {
	f := &fakeCerts{}
	rec := do(t, newCertServer(f), "POST", "/v1/certificates/issue",
		`{"name":"shop","domains":["shop.example.com"],"email":"a@b.co","staging":true,"agree_tos":true}`)
	if rec.Code != 200 || len(f.issued) != 1 || !f.issued[0].Staging || !strings.Contains(rec.Body.String(), `"serial":"abc"`) {
		t.Errorf("code=%d issued=%v body=%s", rec.Code, f.issued, rec.Body)
	}
}

func TestIssueCertificateRejectsBadInput(t *testing.T) {
	for name, body := range map[string]string{
		"no agreement":  `{"name":"shop","domains":["a.example.com"]}`,
		"unknown field": `{"name":"shop","domains":["a.example.com"],"agree_tos":true,"directory":"http://evil/"}`,
		"not json":      `x`,
	} {
		f := &fakeCerts{}
		if rec := do(t, newCertServer(f), "POST", "/v1/certificates/issue", body); rec.Code != http.StatusBadRequest || len(f.issued) != 0 {
			t.Errorf("%s: code=%d issued=%v", name, rec.Code, f.issued)
		}
	}
}

func TestCertificateInfoAndRemove(t *testing.T) {
	f := &fakeCerts{}
	s := newCertServer(f)

	if rec := do(t, s, "GET", "/v1/certificates/shop", ""); rec.Code != http.StatusNotFound {
		t.Errorf("missing: code = %d, want 404", rec.Code)
	}
	f.have = &certs.Info{Name: "shop", Domains: []string{"shop.example.com"}}
	if rec := do(t, s, "GET", "/v1/certificates/shop", ""); rec.Code != 200 || !strings.Contains(rec.Body.String(), "shop.example.com") {
		t.Errorf("info: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, s, "DELETE", "/v1/certificates/shop", ""); rec.Code != 200 || f.removed[0] != "shop" {
		t.Errorf("remove: %d %v", rec.Code, f.removed)
	}
}

func TestServerPageEndpoints(t *testing.T) {
	n := &fakeNginx{}
	s := New(Options{
		Nginx: n, Docker: &fakeDocker{}, Authorize: AllowAll(), Version: "test",
		Info: func(context.Context) system.Info {
			return system.Info{Hostname: "box", Services: []system.Service{{Name: "nginx", State: "active"}}}
		},
	})

	for path, want := range map[string]string{
		"/v1/system/info":   `"hostname":"box"`,
		"/v1/nginx/status":  `"version":"1.26.0"`,
		"/v1/docker/info":   `"server_version":"27.0.1"`,
		"/v1/docker/images": `"commypanel/demo:1"`,
	} {
		rec := do(t, s, "GET", path, "")
		if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), want) {
			t.Errorf("GET %s = %d %s, want %s", path, rec.Code, rec.Body.String(), want)
		}
	}

	if rec := do(t, s, "POST", "/v1/nginx/test", ""); rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), `"ok":true`) {
		t.Errorf("test = %d %s", rec.Code, rec.Body.String())
	}
	if rec := do(t, s, "POST", "/v1/nginx/reload", ""); rec.Code != http.StatusOK || n.reloaded != 1 {
		t.Errorf("reload = %d, reloaded %d times", rec.Code, n.reloaded)
	}
}

func TestServerPageEndpointsNeedAuthorization(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}})
	for _, path := range []string{"/v1/system/info", "/v1/nginx/status", "/v1/docker/info", "/v1/docker/images"} {
		if rec := do(t, s, "GET", path, ""); rec.Code != http.StatusForbidden {
			t.Errorf("GET %s = %d, want 403", path, rec.Code)
		}
	}
}

type fakeUnit struct {
	restarted int
	err       error
}

func (f *fakeUnit) Diagnose(context.Context) system.DockerDiagnosis {
	return system.DockerDiagnosis{Service: "failed", Socket: "active", Journal: "boom"}
}
func (f *fakeUnit) Restart(context.Context) error {
	f.restarted++
	return f.err
}

func TestStatusAsksDockerWhetherItAnswers(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{err: fmt.Errorf("%w: dial", docker.ErrUnavailable)}, Authorize: AllowAll()})
	if rec := do(t, s, "GET", "/v1/status", ""); !strings.Contains(rec.Body.String(), `"docker_available":false`) {
		t.Errorf("down: %s", rec.Body)
	}
	s = New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Authorize: AllowAll()})
	if rec := do(t, s, "GET", "/v1/status", ""); !strings.Contains(rec.Body.String(), `"docker_available":true`) {
		t.Errorf("up: %s", rec.Body)
	}
}

func TestDockerDiagnoseAndRestart(t *testing.T) {
	unit := &fakeUnit{}
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{err: errors.New("docker is not available: dial")}, Unit: unit, Authorize: AllowAll()})

	rec := do(t, s, "GET", "/v1/docker/diagnose", "")
	var got map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &got)
	if got["available"] != false || got["service"] != "failed" || got["socket"] != "active" || got["journal"] != "boom" || got["error"] == nil {
		t.Errorf("diagnose = %v", got)
	}

	if rec := do(t, s, "POST", "/v1/docker/restart", ""); rec.Code != 200 || unit.restarted != 1 {
		t.Errorf("restart: %d %d", rec.Code, unit.restarted)
	}
	unit.err = errors.New("restarting Docker failed: Job for docker.service failed.")
	if rec := do(t, s, "POST", "/v1/docker/restart", ""); rec.Code != 500 || !strings.Contains(rec.Body.String(), "Job for docker.service failed") {
		t.Errorf("failed restart: %d %s", rec.Code, rec.Body)
	}
}

type fakeUsage struct{ history []bool }

func (f *fakeUsage) Snapshot(history bool) usage.Snapshot {
	f.history = append(f.history, history)
	return usage.Snapshot{IntervalMS: 2000, Host: usage.HostNow{Ready: true, CPUPercent: 12.5, CPUs: 4}, Containers: []usage.ContainerUsage{{Name: "shop-1", CPUPercent: 3}}}
}

func TestSystemUsage(t *testing.T) {
	f := &fakeUsage{}
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Usage: f, Authorize: AllowAll()})

	rec := do(t, s, "GET", "/v1/system/usage", "")
	var got usage.Snapshot
	if err := json.Unmarshal(rec.Body.Bytes(), &got); err != nil || rec.Code != 200 {
		t.Fatalf("%d %s %v", rec.Code, rec.Body, err)
	}
	if got.Host.CPUPercent != 12.5 || len(got.Containers) != 1 || got.Containers[0].Name != "shop-1" {
		t.Errorf("snapshot = %+v", got)
	}

	do(t, s, "GET", "/v1/system/usage?history=1", "")
	if len(f.history) != 2 || f.history[0] || !f.history[1] {
		t.Errorf("history flags = %v, want [false true]", f.history)
	}

	if rec := do(t, New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Authorize: AllowAll()}), "GET", "/v1/system/usage", ""); rec.Code != http.StatusNotImplemented {
		t.Errorf("without a sampler: %d", rec.Code)
	}
}

func TestCustomCheckRoute(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Authorize: AllowAll()})

	rec := do(t, s, "POST", "/v1/nginx/custom/check", `{"custom":"# big uploads\nclient_max_body_size 50m;"}`)
	var got struct {
		OK     bool   `json:"ok"`
		Custom string `json:"custom"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &got); err != nil || rec.Code != 200 || !got.OK {
		t.Fatalf("%d %s", rec.Code, rec.Body)
	}
	if got.Custom != "    # big uploads\n    client_max_body_size 50m;\n" {
		t.Errorf("canonical = %q", got.Custom)
	}

	rec = do(t, s, "POST", "/v1/nginx/custom/check", `{"custom":"gzip on;\nroot /;"}`)
	if rec.Code != http.StatusBadRequest || !strings.Contains(rec.Body.String(), "line 2") || !strings.Contains(rec.Body.String(), "root") {
		t.Errorf("refusal: %d %s", rec.Code, rec.Body)
	}

	if rec := do(t, s, "POST", "/v1/nginx/custom/check", `{"custom":"","extra":1}`); rec.Code != http.StatusBadRequest {
		t.Errorf("unknown field: %d", rec.Code)
	}
}

func TestRenderRoute(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Authorize: AllowAll()})

	rec := do(t, s, "POST", "/v1/nginx/render", `{"name":"shop","domains":["shop.example.com"],"upstream_port":20007,"custom":"gzip on;"}`)
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `# rendered shop\ngzip on;`) {
		t.Errorf("render: %d %s", rec.Code, rec.Body)
	}
	rec = do(t, s, "POST", "/v1/nginx/render", `{"name":"shop","domains":["shop.example.com"],"upstream_port":20007,"custom":"alias /;"}`)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("a refused site must not render: %d", rec.Code)
	}
}

func TestApplySiteRefusesBadCustomDirectives(t *testing.T) {
	f := &fakeNginx{}
	s := New(Options{Nginx: f, Docker: &fakeDocker{}, Authorize: AllowAll()})

	rec := do(t, s, "PUT", "/v1/nginx/sites", `{"name":"shop","domains":["shop.example.com"],"upstream_port":20007,"custom":"include /etc/passwd;"}`)
	if rec.Code != http.StatusBadRequest || len(f.applied) != 0 {
		t.Errorf("%d applied=%d", rec.Code, len(f.applied))
	}
}

func volumeServer(t *testing.T) *Server {
	t.Helper()
	dir := t.TempDir()
	vol := filepath.Join(dir, "volumes", "shop", "uploads")
	if err := os.MkdirAll(filepath.Join(vol, "photos"), 0o755); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(vol, "readme.txt"), []byte("hello"), 0o644)
	os.WriteFile(filepath.Join(dir, "secret"), []byte("TOP SECRET"), 0o600)
	os.Symlink(filepath.Join(dir, "secret"), filepath.Join(vol, "leak"))
	return New(Options{Nginx: &fakeNginx{}, Volumes: volumefs.New(dir), Authorize: AllowAll()})
}

func TestVolumeReadRoutes(t *testing.T) {
	s := volumeServer(t)

	rec := do(t, s, "GET", "/v1/volumes/shop", "")
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"name":"uploads"`) || !strings.Contains(rec.Body.String(), `"bytes":5`) {
		t.Errorf("list: %d %s", rec.Code, rec.Body)
	}

	rec = do(t, s, "GET", "/v1/volumes/shop/uploads/dir", "")
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"name":"photos","kind":"dir"`) || !strings.Contains(rec.Body.String(), `"name":"leak","kind":"link"`) {
		t.Errorf("dir: %d %s", rec.Code, rec.Body)
	}

	rec = do(t, s, "GET", "/v1/volumes/shop/uploads/file?path=readme.txt", "")
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"content":"hello"`) {
		t.Errorf("file: %d %s", rec.Code, rec.Body)
	}

	rec = do(t, s, "GET", "/v1/volumes/shop/uploads/download?path=readme.txt", "")
	if rec.Code != 200 || rec.Body.String() != "hello" || rec.Header().Get("Content-Length") != "5" ||
		rec.Header().Get("Content-Type") != "application/octet-stream" {
		t.Errorf("download: %d %q %v", rec.Code, rec.Body, rec.Header())
	}
}

func TestVolumeReadRoutesRefuseEscapes(t *testing.T) {
	s := volumeServer(t)

	for _, path := range []string{
		"/v1/volumes/shop/uploads/file?path=leak",
		"/v1/volumes/shop/uploads/download?path=leak",
		"/v1/volumes/shop/uploads/file?path=../../../secret",
		"/v1/volumes/shop/uploads/file?path=/etc/passwd",
		"/v1/volumes/shop/uploads/dir?path=..",
		"/v1/volumes/shop/..%2Fuploads/dir",
		"/v1/volumes/..%2Fx",
	} {
		rec := do(t, s, "GET", path, "")
		if rec.Code == 200 || strings.Contains(rec.Body.String(), "TOP SECRET") {
			t.Errorf("%s: %d %s", path, rec.Code, rec.Body)
		}
	}
}

func TestVolumeRoutesNeedAuthorization(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Volumes: volumefs.New(t.TempDir())})
	for _, path := range []string{"/v1/volumes/shop", "/v1/volumes/shop/uploads/dir", "/v1/volumes/shop/uploads/file", "/v1/volumes/shop/uploads/download"} {
		if rec := do(t, s, "GET", path, ""); rec.Code != http.StatusForbidden {
			t.Errorf("%s: %d, want 403", path, rec.Code)
		}
	}
}

type fakeTerminal struct {
	container, origin string
	err               error
}

func (f *fakeTerminal) Issue(container, origin string) (string, error) {
	f.container, f.origin = container, origin
	return "ticket-123", f.err
}

func TestTerminalTicketRoute(t *testing.T) {
	f := &fakeTerminal{}
	s := New(Options{Nginx: &fakeNginx{}, Terminal: f, Authorize: AllowAll()})

	rec := do(t, s, "POST", "/v1/terminal/tickets", `{"container":"shop-1","origin":"https://panel.example.com"}`)
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"ticket":"ticket-123"`) || !strings.Contains(rec.Body.String(), `"expires_in":30`) {
		t.Errorf("ticket: %d %s", rec.Code, rec.Body)
	}
	if f.container != "shop-1" || f.origin != "https://panel.example.com" {
		t.Errorf("issued for %q %q", f.container, f.origin)
	}

	if rec := do(t, s, "POST", "/v1/terminal/tickets", `{"container":"shop-1","origin":"x","extra":1}`); rec.Code != 400 {
		t.Errorf("unknown field: %d", rec.Code)
	}

	f.err = &validate.InputError{Msg: "invalid origin"}
	if rec := do(t, s, "POST", "/v1/terminal/tickets", `{"container":"shop-1","origin":"x"}`); rec.Code != 400 {
		t.Errorf("bad input: %d", rec.Code)
	}
	f.err = terminal.ErrTooManyTickets
	if rec := do(t, s, "POST", "/v1/terminal/tickets", `{"container":"shop-1","origin":"https://a.example.com"}`); rec.Code != 429 {
		t.Errorf("too many: %d", rec.Code)
	}
}

func TestTerminalTicketsWhenTheAgentHasTerminalsOff(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll()})
	if rec := do(t, s, "POST", "/v1/terminal/tickets", `{"container":"shop-1","origin":"https://a.example.com"}`); rec.Code != 503 {
		t.Errorf("code = %d, want 503", rec.Code)
	}
}

func TestTerminalTicketsNeedAuthorization(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Terminal: &fakeTerminal{}})
	if rec := do(t, s, "POST", "/v1/terminal/tickets", `{}`); rec.Code != http.StatusForbidden {
		t.Errorf("code = %d, want 403", rec.Code)
	}
}

func TestDatabaseRoutes(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)
	target := `"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234"`

	if rec := do(t, s, "POST", "/v1/images/pull", `{"image":"postgres:17"}`); rec.Code != 200 {
		t.Errorf("pull: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, s, "POST", "/v1/images/pull", `{"image":"evil/image:1"}`); rec.Code != 400 {
		t.Errorf("a foreign image must be refused: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/databases/ready", `{`+target+`}`); rec.Code != 200 || !strings.Contains(rec.Body.String(), `"ready":true`) {
		t.Errorf("ready: %d %s", rec.Code, rec.Body)
	}
	f.notReady = true
	if rec := do(t, s, "POST", "/v1/databases/ready", `{`+target+`}`); !strings.Contains(rec.Body.String(), `"ready":false`) {
		t.Errorf("not ready: %s", rec.Body)
	}
	if rec := do(t, s, "POST", "/v1/databases/create", `{`+target+`,"database":"shop","user":"shop","password":"abcdefghijklmnop1234"}`); rec.Code != 200 {
		t.Errorf("create: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, s, "POST", "/v1/databases/create", `{`+target+`,"database":"sh op","user":"shop","password":"x"}`); rec.Code != 400 {
		t.Errorf("a bad name must be refused: %d", rec.Code)
	}
	do(t, s, "POST", "/v1/databases/drop", `{`+target+`,"database":"shop","user":"shop"}`)
	do(t, s, "POST", "/v1/databases/password", `{`+target+`,"user":"shop","password":"abcdefghijklmnop1234"}`)
	do(t, s, "POST", "/v1/databases/remove-data", `{"server":"main"}`)
	if rec := do(t, s, "POST", "/v1/databases/create", `{`+target+`,"database":"shop","extra":1}`); rec.Code != 400 {
		t.Errorf("unknown fields must be refused: %d", rec.Code)
	}
	want := "pull postgres:17|create db-main shop shop|drop db-main shop shop|password db-main shop|remove-data main"
	if got := strings.Join(f.calls, "|"); got != want {
		t.Errorf("calls = %s", got)
	}
}

func TestLinkNetworkRoutes(t *testing.T) {
	f := &fakeDocker{}
	s := newDockerServer(f)

	if rec := do(t, s, "POST", "/v1/docker/networks/connect", `{"container":"db-main","network":"commypanel-link-d3","aliases":["main"]}`); rec.Code != 200 {
		t.Errorf("connect: %d %s", rec.Code, rec.Body)
	}
	for _, bad := range []string{`{"container":"db-main","network":"host"}`, `{"container":"db-main","network":"commypanel-shop"}`, `{"container":"db-main","network":"commypanel"}`, `{"container":"db-main","network":"bridge"}`} {
		if rec := do(t, s, "POST", "/v1/docker/networks/connect", bad); rec.Code != 400 {
			t.Errorf("%s: %d, want 400", bad, rec.Code)
		}
	}
	do(t, s, "POST", "/v1/docker/networks/disconnect", `{"container":"db-main","network":"commypanel-link-d3"}`)
	if rec := do(t, s, "POST", "/v1/docker/networks/prune", `{}`); rec.Code != 200 || !strings.Contains(rec.Body.String(), "commypanel-link-d1") {
		t.Errorf("prune: %d %s", rec.Code, rec.Body)
	}
	if got := strings.Join(f.calls, "|"); got != "connect db-main commypanel-link-d3 main|disconnect db-main commypanel-link-d3" {
		t.Errorf("calls = %s", got)
	}
}

func TestDatabaseDumpRouteStreamsAGzippedFileAndRemovesTheTemporaryOne(t *testing.T) {
	s := newDockerServer(&fakeDocker{})
	target := `"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234"`

	rec := do(t, s, "POST", "/v1/databases/dump", `{`+target+`,"database":"shop"}`)
	if rec.Code != 200 || rec.Header().Get("Content-Type") != "application/gzip" {
		t.Fatalf("dump: %d %v", rec.Code, rec.Header())
	}
	zr, err := gzip.NewReader(rec.Body)
	if err != nil {
		t.Fatal(err)
	}
	text, _ := io.ReadAll(zr)
	if string(text) != "-- dump of shop from db-main\n" {
		t.Errorf("content = %q", text)
	}

	if rec := do(t, s, "POST", "/v1/databases/dump", `{`+target+`,"database":"sh op"}`); rec.Code != 400 || strings.Contains(rec.Header().Get("Content-Type"), "gzip") {
		t.Errorf("a bad name must be a plain error: %d %v", rec.Code, rec.Header())
	}
}

func TestDatabaseDumpRouteRemembersTheChecksumOfWhatItSent(t *testing.T) {
	d := &fakeDocker{}
	s := newDockerServer(d)
	target := `"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234"`

	rec := do(t, s, "POST", "/v1/databases/dump", `{`+target+`,"database":"shop"}`)
	sum := sha256.Sum256(rec.Body.Bytes())

	want := "register " + hex.EncodeToString(sum[:])
	if len(d.calls) != 1 || d.calls[0] != want {
		t.Errorf("calls = %v, want [%s]", d.calls, want)
	}
}

func TestDatabaseRestoreRoutePassesTheFileOnWithoutTheSecrets(t *testing.T) {
	d := &fakeDocker{}
	s := newDockerServer(d)
	target := `"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234"`

	rec := do(t, s, "POST", "/v1/databases/restore", `{`+target+`,"database":"shop2","user":"shop2","server":"main","file":"shop-20261103-040000.sql.gz"}`)
	if rec.Code != 200 {
		t.Fatalf("restore: %d %s", rec.Code, rec.Body)
	}
	if len(d.calls) != 1 || d.calls[0] != "restore db-main shop2 shop2 main shop-20261103-040000.sql.gz" {
		t.Errorf("calls = %v", d.calls)
	}
}

func TestDatabaseRestoreRouteReplaceModeAndUnknownModes(t *testing.T) {
	d := &fakeDocker{}
	s := newDockerServer(d)
	target := `"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234"`
	rest := `"database":"shop","user":"shop","server":"main","file":"shop-20261103-040000.sql.gz"`

	rec := do(t, s, "POST", "/v1/databases/restore", `{`+target+`,`+rest+`,"mode":"replace"}`)
	if rec.Code != 200 || len(d.calls) != 1 || d.calls[0] != "replace db-main shop shop main shop-20261103-040000.sql.gz" {
		t.Fatalf("replace: %d %s %v", rec.Code, rec.Body, d.calls)
	}
	d.calls = nil
	if rec := do(t, s, "POST", "/v1/databases/restore", `{`+target+`,`+rest+`,"mode":""}`); rec.Code != 200 || !strings.HasPrefix(d.calls[0], "restore ") {
		t.Errorf("empty mode must be the old behaviour: %d %v", rec.Code, d.calls)
	}
	d.calls = nil
	for _, mode := range []string{"new", "REPLACE", "drop"} {
		if rec := do(t, s, "POST", "/v1/databases/restore", `{`+target+`,`+rest+`,"mode":"`+mode+`"}`); rec.Code != 400 {
			t.Errorf("mode %q: %d", mode, rec.Code)
		}
	}
	if len(d.calls) != 0 {
		t.Errorf("calls = %v", d.calls)
	}
	d.err = &validate.InputError{Msg: "the database \"shop\" does not exist on this server, so there is nothing to replace"}
	rec = do(t, s, "POST", "/v1/databases/restore", `{`+target+`,`+rest+`,"mode":"replace"}`)
	if rec.Code != 400 || !strings.Contains(rec.Body.String(), `"error":`) {
		t.Errorf("error answer: %d %s", rec.Code, rec.Body)
	}
}

func TestVolumeRestoreRoute(t *testing.T) {
	d := &fakeDocker{}
	s := newDockerServer(d)
	rec := do(t, s, "POST", "/v1/volumes/restore", `{"app":"shop","volume":"uploads","archive":"uploads-20261103-040000.tar.gz"}`)
	if rec.Code != 200 || strings.TrimSpace(rec.Body.String()) != `{"bytes":42,"files":3,"ok":true}` {
		t.Fatalf("restore: %d %s", rec.Code, rec.Body)
	}
	if len(d.calls) != 1 || d.calls[0] != "restore-volume shop uploads uploads-20261103-040000.tar.gz" {
		t.Errorf("calls = %v", d.calls)
	}
	if rec := do(t, s, "POST", "/v1/volumes/restore", `{"app":"shop","volume":"uploads"}`); rec.Code != 200 {
		// The fake accepts anything; validation is the manager's, tested there.
		t.Logf("missing archive reaches the manager: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/volumes/restore", `{"app":"shop","volume":"uploads","archive":"x","extra":1}`); rec.Code != 400 {
		t.Errorf("an unknown field: %d", rec.Code)
	}
	d.err = &validate.InputError{Msg: "container shop-1 is running with this volume mounted; stop the app first"}
	if rec := do(t, s, "POST", "/v1/volumes/restore", `{"app":"shop","volume":"uploads","archive":"uploads-20261103-040000.tar.gz"}`); rec.Code != 400 || !strings.Contains(rec.Body.String(), "stop the app first") {
		t.Errorf("a refusal: %d %s", rec.Code, rec.Body)
	}
}

func TestVolumeBackupRouteStreamsAGzippedArchiveAndLeavesNoCopy(t *testing.T) {
	dir := t.TempDir()
	vol := filepath.Join(dir, "volumes", "shop", "uploads")
	if err := os.MkdirAll(vol, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(vol, "a.txt"), []byte("alpha"), 0o644); err != nil {
		t.Fatal(err)
	}
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Volumes: volumefs.New(dir), Authorize: AllowAll()})

	rec := do(t, s, "POST", "/v1/volumes/backup", `{"app":"shop","name":"uploads"}`)
	if rec.Code != 200 || rec.Header().Get("Content-Type") != "application/gzip" {
		t.Fatalf("backup: %d %v %s", rec.Code, rec.Header(), rec.Body)
	}
	fd := s.opts.Docker.(*fakeDocker)
	if len(fd.calls) != 1 || !strings.HasPrefix(fd.calls[0], "register-volume ") {
		t.Errorf("the archive was not remembered: %v", fd.calls)
	}
	zr, err := gzip.NewReader(rec.Body)
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(zr)
	hdr, err := tr.Next()
	if err != nil || hdr.Name != "a.txt" {
		t.Fatalf("first entry = %v, %v", hdr, err)
	}

	if rec := do(t, s, "POST", "/v1/volumes/backup", `{"app":"../x","name":"uploads"}`); rec.Code != 400 {
		t.Errorf("a bad name: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/volumes/backup", `{"app":"shop","name":"missing"}`); rec.Code == 200 {
		t.Errorf("a missing volume was backed up")
	}
}

// blockingDocker holds every dump until it is released, to have several running at once.
type blockingDocker struct {
	fakeDocker
	release chan struct{}
}

// RegisterDump is called by two dumps at once here; the shared fake it would append to is not for that.
func (b *blockingDocker) RegisterDump(string) error { return nil }

func (b *blockingDocker) DumpDatabase(ctx context.Context, t docker.DatabaseTarget, db string) (string, error) {
	<-b.release
	return b.fakeDocker.DumpDatabase(ctx, t, db)
}

func TestOnlyAFewBigJobsRunAtOnce(t *testing.T) {
	d := &blockingDocker{release: make(chan struct{})}
	s := New(Options{Nginx: &fakeNginx{}, Docker: d, Authorize: AllowAll()})
	body := `{"container":"db-main","engine":"postgres","root_password":"abcdefghijklmnop1234","database":"shop"}`

	var wg sync.WaitGroup
	for i := 0; i < maxHeavyJobs; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			do(t, s, "POST", "/v1/databases/dump", body)
		}()
	}
	// Wait until both hold their place.
	for i := 0; i < 200 && len(s.heavy) < maxHeavyJobs; i++ {
		time.Sleep(5 * time.Millisecond)
	}

	if rec := do(t, s, "POST", "/v1/databases/dump", body); rec.Code != 429 {
		t.Errorf("a third dump got %d, want 429", rec.Code)
	}

	close(d.release)
	wg.Wait()

	if rec := do(t, s, "POST", "/v1/databases/dump", body); rec.Code != 200 {
		t.Errorf("after they finished a dump got %d", rec.Code)
	}
}

func TestAddressesRouteAnswersWithThePublicAddressesOfThisMachine(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll(), Addresses: func() system.Addresses {
		return system.Addresses{IPv4: []string{"65.21.10.20"}, IPv6: []string{}, PrimaryIPv4: "65.21.10.20"}
	}})

	rec := do(t, s, "GET", "/v1/system/addresses", "")
	if rec.Code != 200 || !strings.Contains(rec.Body.String(), `"ipv4":["65.21.10.20"]`) || !strings.Contains(rec.Body.String(), `"primary_ipv4":"65.21.10.20"`) {
		t.Errorf("addresses: %d %s", rec.Code, rec.Body)
	}
}

// fakeTools stands in for the database tool sessions.
type fakeTools struct {
	started dbtool.StartRequest
	ready   bool
	stopped []string
	err     error
}

func (f *fakeTools) Start(req dbtool.StartRequest) (dbtool.View, error) {
	f.started = req
	if f.err != nil {
		return dbtool.View{}, f.err
	}
	return dbtool.View{ID: "abcdefghijklmnopqrstuvwx", Stage: dbtool.StagePulling, Tool: "phpmyadmin", Server: req.Server, Database: req.Database}, nil
}

func (f *fakeTools) Status(id string) (dbtool.View, bool) {
	if id != "abcdefghijklmnopqrstuvwx" {
		return dbtool.View{}, false
	}
	stage := dbtool.StageStarting
	if f.ready {
		stage = dbtool.StageReady
	}
	return dbtool.View{ID: id, Stage: stage, Tool: "phpmyadmin"}, true
}

func (f *fakeTools) Ticket(id string) (string, error) {
	if !f.ready {
		return "", dbtool.ErrNotReady
	}
	return "ticket-for-" + id, nil
}

func (f *fakeTools) Stop(id string) { f.stopped = append(f.stopped, id) }

func TestDatabaseToolRoutesStartFollowTicketAndStop(t *testing.T) {
	tools := &fakeTools{}
	s := New(Options{Nginx: &fakeNginx{}, DBTools: tools, Authorize: AllowAll()})
	body := `{"engine":"mariadb","server":"main","database":"shop","user":"shop","password":"abcdefghijklmnop1234","public_base":"https://commy.example.com:2053"}`

	rec := do(t, s, "POST", "/v1/dbtools/start", body)
	if rec.Code != 202 || !strings.Contains(rec.Body.String(), `"stage":"pulling"`) || tools.started.Database != "shop" || tools.started.Password != "abcdefghijklmnop1234" {
		t.Fatalf("start: %d %s", rec.Code, rec.Body)
	}
	if strings.Contains(rec.Body.String(), "abcdefghijklmnop1234") {
		t.Error("the password is in the answer")
	}

	id := "abcdefghijklmnopqrstuvwx"
	if rec := do(t, s, "GET", "/v1/dbtools/"+id, ""); rec.Code != 200 || !strings.Contains(rec.Body.String(), `"stage":"starting"`) {
		t.Errorf("status: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, s, "POST", "/v1/dbtools/"+id+"/ticket", ""); rec.Code != 400 {
		t.Errorf("a ticket before it is ready: %d %s", rec.Code, rec.Body)
	}
	tools.ready = true
	if rec := do(t, s, "POST", "/v1/dbtools/"+id+"/ticket", ""); rec.Code != 200 || !strings.Contains(rec.Body.String(), "ticket-for-"+id) {
		t.Errorf("ticket: %d %s", rec.Code, rec.Body)
	}
	if rec := do(t, s, "POST", "/v1/dbtools/"+id+"/stop", ""); rec.Code != 200 || len(tools.stopped) != 1 {
		t.Errorf("stop: %d %v", rec.Code, tools.stopped)
	}
}

func TestDatabaseToolRoutesRefuseOddIDsAndUnknownSessionsAndBadInput(t *testing.T) {
	tools := &fakeTools{err: &validate.InputError{Msg: "too many database tools are open"}}
	s := New(Options{Nginx: &fakeNginx{}, DBTools: tools, Authorize: AllowAll()})

	for _, id := range []string{"short", "has.dots.in.it.and.is.long", "../../etc/passwd/xxxxxxxx", strings.Repeat("a", 65)} {
		// A path with .. is cleaned by the router, which answers with a redirect; the rest are refused.
		if rec := do(t, s, "GET", "/v1/dbtools/"+id, ""); rec.Code != 404 && rec.Code != 307 {
			t.Errorf("status of %q: %d", id, rec.Code)
		}
		if rec := do(t, s, "POST", "/v1/dbtools/"+id+"/stop", ""); rec.Code != 404 && rec.Code != 307 {
			t.Errorf("stop of %q: %d", id, rec.Code)
		}
		if len(tools.stopped) != 0 {
			t.Errorf("a session was stopped through %q", id)
		}
	}
	if rec := do(t, s, "GET", "/v1/dbtools/zzzzzzzzzzzzzzzzzzzzzzzz", ""); rec.Code != 404 {
		t.Errorf("an unknown session: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/dbtools/start", `{"engine":"mariadb","unknown":1}`); rec.Code != 400 {
		t.Errorf("an unknown field: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/dbtools/start", `{"engine":"mariadb","server":"main","database":"shop","user":"shop","password":"abcdefghijklmnop1234","public_base":"https://a.example"}`); rec.Code != 400 || !strings.Contains(rec.Body.String(), "too many") {
		t.Errorf("a refusal: %d %s", rec.Code, rec.Body)
	}
}

func TestDatabaseToolsCanBeOff(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll()})
	if rec := do(t, s, "POST", "/v1/dbtools/start", `{}`); rec.Code != 501 {
		t.Errorf("start with tools off: %d", rec.Code)
	}
}

type fakeTraffic struct{ got traffic.Query }

func (f *fakeTraffic) Range(site string, q traffic.Query) map[int]*traffic.Stats {
	f.got = q
	if site != "shop" {
		return map[int]*traffic.Stats{}
	}
	return map[int]*traffic.Stats{20001: {Requests: 10, Errors: 1, P95: 100}}
}

func TestTrafficRange(t *testing.T) {
	f := &fakeTraffic{}
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Traffic: f, Authorize: AllowAll()})

	rec := do(t, s, "GET", "/v1/traffic/shop?from=1800000000&to=1800000300&step=30", "")
	var got struct {
		Site  string                   `json:"site"`
		Ports map[string]traffic.Stats `json:"ports"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &got); err != nil || rec.Code != 200 {
		t.Fatalf("%d %s %v", rec.Code, rec.Body, err)
	}
	if got.Site != "shop" || got.Ports["20001"].Requests != 10 || got.Ports["20001"].Errors != 1 {
		t.Errorf("answer = %+v", got)
	}
	if f.got.From != 1800000000 || f.got.To != 1800000300 || f.got.Step != 30 {
		t.Errorf("query = %+v", f.got)
	}

	// The default is the last ten minutes.
	do(t, s, "GET", "/v1/traffic/shop", "")
	if span := f.got.To - f.got.From; span < 590 || span > 620 {
		t.Errorf("default span = %d", span)
	}
}

func TestTrafficRangeRefusesBadInput(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Traffic: &fakeTraffic{}, Authorize: AllowAll()})
	for name, path := range map[string]string{
		"a bad site":        "/v1/traffic/Not_A_Site",
		"a text time":       "/v1/traffic/shop?from=yesterday",
		"an inverted range": "/v1/traffic/shop?from=1800000300&to=1800000000",
		"over an hour":      "/v1/traffic/shop?from=1800000000&to=1800009000",
		"a tiny step":       "/v1/traffic/shop?step=1",
		"an odd step":       "/v1/traffic/shop?step=15",
		"a huge step":       "/v1/traffic/shop?step=7200",
	} {
		if rec := do(t, s, "GET", path, ""); rec.Code != http.StatusBadRequest {
			t.Errorf("%s: %d %s", name, rec.Code, rec.Body)
		}
	}
	if rec := do(t, New(Options{Nginx: &fakeNginx{}, Docker: &fakeDocker{}, Authorize: AllowAll()}), "GET", "/v1/traffic/shop", ""); rec.Code != http.StatusNotImplemented {
		t.Errorf("without statistics: %d", rec.Code)
	}
}
