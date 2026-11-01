package server

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"

	"commypanel/agent/internal/certs"
	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/nginx"
	"commypanel/agent/internal/system"
	"commypanel/agent/internal/usage"
	"commypanel/agent/internal/validate"
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
	ran           []docker.ContainerSpec
	stopped       []string
	started       []string
	restarted     []string
	removed       []string
	tasks         []docker.TaskSpec
	taskCode      int
	err           error
	buildErr      error
	buildLogs     []string
	removedImages []string
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
