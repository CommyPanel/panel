package nginx

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

type fakeRunner struct {
	calls   []string
	failOn  string // "test" or "reload"
	version string // output of `nginx -v`
}

func (f *fakeRunner) Run(_ context.Context, name string, args ...string) ([]byte, error) {
	call := name + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	if call == "nginx -v" {
		return []byte(f.version), nil
	}
	if f.failOn == "test" && strings.HasSuffix(call, "-t") {
		return []byte("bad config"), errors.New("exit 1")
	}
	if f.failOn == "reload" && strings.HasSuffix(call, "reload") {
		return []byte("reload failed"), errors.New("exit 1")
	}
	return nil, nil
}

func newManager(t *testing.T, run Runner, dry bool) (*Manager, string) {
	t.Helper()
	dir := t.TempDir()
	return NewManager(Config{SitesDir: filepath.Join(dir, "sites"), DataDir: filepath.Join(dir, "data"), StateDir: filepath.Join(dir, "state"), DryRun: dry}, run), dir
}

var site = Site{Name: "demo", Domains: []string{"demo.example.com", "www.example.com"}, UpstreamPort: 20001}

func TestRenderHTTPOnly(t *testing.T) {
	m, _ := newManager(t, nil, true)
	conf, err := m.Render(site)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"server_name demo.example.com www.example.com;",
		"proxy_pass http://127.0.0.1:20001;",
		"location ^~ /.well-known/acme-challenge/",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("config missing %q:\n%s", want, conf)
		}
	}
	if strings.Contains(conf, "listen 443") || strings.Contains(conf, "return 301") {
		t.Errorf("http-only site must not have TLS or redirect:\n%s", conf)
	}
}

func TestRenderWithCertificate(t *testing.T) {
	m, dir := newManager(t, nil, true)
	s := site
	s.Certificate = "demo"
	conf, err := m.Render(s)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"listen 443 ssl;",
		"return 301 https://$host$request_uri;",
		"ssl_certificate " + filepath.Join(dir, "state", "certs", "demo") + "/fullchain.pem;",
		"ssl_certificate_key " + filepath.Join(dir, "state", "certs", "demo") + "/privkey.pem;",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("config missing %q:\n%s", want, conf)
		}
	}
}

func TestRejectsInjection(t *testing.T) {
	m, _ := newManager(t, nil, true)
	bad := []Site{
		{Name: "../evil", Domains: []string{"a.example.com"}, UpstreamPort: 20001},
		{Name: "ok", Domains: []string{"a.example.com; }\nserver { listen 1;"}, UpstreamPort: 20001},
		{Name: "ok", Domains: []string{"a.example.com\n"}, UpstreamPort: 20001},
		{Name: "ok", Domains: nil, UpstreamPort: 20001},
		{Name: "ok", Domains: []string{"a.example.com"}, UpstreamPort: 80},
		{Name: "ok", Domains: []string{"a.example.com"}, UpstreamPort: 20001, Certificate: "../../etc"},
	}
	for i, s := range bad {
		_, err := m.Render(s)
		var ie *validate.InputError
		if !errors.As(err, &ie) {
			t.Errorf("case %d: want *InputError, got %v", i, err)
		}
	}
}

func TestApplyRunsTestThenReload(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, false)
	if err := m.Apply(context.Background(), site); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(run.calls, "|"); got != "nginx -t|nginx -s reload" {
		t.Errorf("calls = %q", got)
	}
	if _, err := os.Stat(m.path("demo")); err != nil {
		t.Errorf("config not written: %v", err)
	}
}

func TestApplyRollsBackNewSiteWhenTestFails(t *testing.T) {
	run := &fakeRunner{failOn: "test"}
	m, _ := newManager(t, run, false)
	if err := m.Apply(context.Background(), site); err == nil {
		t.Fatal("want error")
	}
	if _, err := os.Stat(m.path("demo")); !os.IsNotExist(err) {
		t.Errorf("rejected config must be removed, stat err = %v", err)
	}
	for _, c := range run.calls {
		if strings.HasSuffix(c, "reload") {
			t.Error("must not reload after a failed test")
		}
	}
}

func TestApplyRestoresPreviousConfigWhenTestFails(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{}, false)
	if err := m.Apply(context.Background(), site); err != nil {
		t.Fatal(err)
	}
	before, _ := os.ReadFile(m.path("demo"))

	m.run = &fakeRunner{failOn: "test"}
	changed := site
	changed.UpstreamPort = 20002
	if err := m.Apply(context.Background(), changed); err == nil {
		t.Fatal("want error")
	}
	after, _ := os.ReadFile(m.path("demo"))
	if string(before) != string(after) {
		t.Error("previous config was not restored")
	}
}

func TestDryRunWritesButNeverRuns(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, true)
	if err := m.Apply(context.Background(), site); err != nil {
		t.Fatal(err)
	}
	if len(run.calls) != 0 {
		t.Errorf("dry run executed %v", run.calls)
	}
	if _, err := os.Stat(m.path("demo")); err != nil {
		t.Errorf("dry run should still write the file: %v", err)
	}
}

func TestRemove(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, false)
	if err := m.Apply(context.Background(), site); err != nil {
		t.Fatal(err)
	}
	run.calls = nil
	if err := m.Remove(context.Background(), "demo"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.path("demo")); !os.IsNotExist(err) {
		t.Error("config should be gone")
	}
	if got := strings.Join(run.calls, "|"); got != "nginx -t|nginx -s reload" {
		t.Errorf("calls = %q", got)
	}
	if err := m.Remove(context.Background(), "demo"); err != nil {
		t.Errorf("removing a missing site should succeed, got %v", err)
	}
	if err := m.Remove(context.Background(), "../x"); err == nil {
		t.Error("invalid name must be rejected")
	}
}

func TestEnsureDefaultSite(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, false)

	if err := m.EnsureDefaultSite(context.Background()); err != nil {
		t.Fatal(err)
	}
	conf, err := os.ReadFile(filepath.Join(m.cfg.SitesDir, "00-commypanel-default.conf"))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"listen 80 default_server;", "location /.well-known/acme-challenge/", "return 404;", "acme-webroot"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("default site missing %q:\n%s", want, conf)
		}
	}
	if got := strings.Join(run.calls, "|"); got != "nginx -t|nginx -s reload" {
		t.Errorf("calls = %q", got)
	}

	// Unchanged content means no second reload.
	run.calls = nil
	if err := m.EnsureDefaultSite(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(run.calls) != 0 {
		t.Errorf("idempotent call reloaded nginx: %v", run.calls)
	}
}

func TestEnsureDefaultSiteIsRemovedWhenNginxRejectsIt(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{failOn: "test"}, false)

	if err := m.EnsureDefaultSite(context.Background()); err == nil {
		t.Fatal("want error")
	}
	if _, err := os.Stat(filepath.Join(m.cfg.SitesDir, "00-commypanel-default.conf")); !os.IsNotExist(err) {
		t.Error("a rejected default site must be removed")
	}
}

func TestEnsureDefaultSiteInDryRun(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, true)

	if err := m.EnsureDefaultSite(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(run.calls) != 0 {
		t.Errorf("dry run executed %v", run.calls)
	}
}

func TestHTTP2SyntaxFollowsTheNginxVersion(t *testing.T) {
	cases := map[string]struct{ modern bool }{
		"nginx version: nginx/1.24.0 (Ubuntu)": {false},
		"nginx version: nginx/1.25.0":          {false},
		"nginx version: nginx/1.25.1":          {true},
		"nginx version: nginx/1.26.3":          {true},
		"nginx version: nginx/1.30.0":          {true},
		"nginx version: nginx/2.0.0":           {true},
		"nginx version: nginx/1.9.15":          {false},
		"something unexpected":                 {false},
		"":                                     {false},
	}
	for output, want := range cases {
		m, _ := newManager(t, &fakeRunner{version: output}, false)
		s := site
		s.Certificate = "demo"
		conf, err := m.Render(s)
		if err != nil {
			t.Fatal(err)
		}
		hasDirective := strings.Contains(conf, "    http2 on;")
		hasListenFlag := strings.Contains(conf, "listen 443 ssl http2;") && strings.Contains(conf, "listen [::]:443 ssl http2;")
		if want.modern && (!hasDirective || hasListenFlag) {
			t.Errorf("%q: want `http2 on;` only:\n%s", output, conf)
		}
		if !want.modern && (hasDirective || !hasListenFlag) {
			t.Errorf("%q: want `ssl http2` listen flags only:\n%s", output, conf)
		}
	}
}

func TestHTTP2IsDetectedOnlyOnce(t *testing.T) {
	run := &fakeRunner{version: "nginx version: nginx/1.26.0"}
	m, _ := newManager(t, run, false)
	s := site
	s.Certificate = "demo"
	for i := 0; i < 3; i++ {
		if _, err := m.Render(s); err != nil {
			t.Fatal(err)
		}
	}
	count := 0
	for _, c := range run.calls {
		if c == "nginx -v" {
			count++
		}
	}
	if count != 1 {
		t.Errorf("nginx -v ran %d times, want 1", count)
	}
}

func TestDryRunNeverAsksNginxForItsVersion(t *testing.T) {
	run := &fakeRunner{}
	m, _ := newManager(t, run, true)
	s := site
	s.Certificate = "demo"
	conf, err := m.Render(s)
	if err != nil {
		t.Fatal(err)
	}
	if len(run.calls) != 0 || !strings.Contains(conf, "http2 on;") {
		t.Errorf("calls=%v conf=%s", run.calls, conf)
	}
}

func TestRenderRedirectOverHTTP(t *testing.T) {
	m, _ := newManager(t, nil, true)
	conf, err := m.Render(Site{
		Name: "shop", Domains: []string{"example.com", "www.example.com"}, UpstreamPort: 20001,
		Redirects: []Redirect{{From: "example.com", To: "www.example.com"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"server_name www.example.com;",
		"server_name example.com;",
		"return 301 http://www.example.com$request_uri;",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("config missing %q:\n%s", want, conf)
		}
	}
	if strings.Count(conf, "proxy_pass") != 1 || strings.Contains(conf, "server_name example.com www") {
		t.Errorf("only the target may proxy:\n%s", conf)
	}
}

func TestRenderRedirectOverHTTPS(t *testing.T) {
	m, _ := newManager(t, nil, true)
	conf, err := m.Render(Site{
		Name: "shop", Domains: []string{"example.com", "www.example.com"}, UpstreamPort: 20001, Certificate: "shop",
		Redirects: []Redirect{{From: "example.com", To: "www.example.com"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	// One 301 to https for the plain port, one on 443 for the redirected name.
	if got := strings.Count(conf, "return 301 https://www.example.com$request_uri;"); got != 2 {
		t.Errorf("want 2 redirects to the https target, got %d:\n%s", got, conf)
	}
	if strings.Count(conf, "443 ssl") < 4 { // IPv4 + IPv6, for the app and for the redirect
		t.Errorf("redirect needs its own TLS server:\n%s", conf)
	}
}

func TestRejectsBadRedirects(t *testing.T) {
	m, _ := newManager(t, nil, true)
	d := []string{"a.example.com", "b.example.com"}
	bad := []Site{
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "a.example.com", To: "a.example.com"}}},
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "a.example.com", To: "c.example.com"}}},
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "x.example.com", To: "b.example.com"}}},
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "a.example.com", To: "b.example.com;\nx"}}},
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "a.example.com", To: "b.example.com"}, {From: "b.example.com", To: "a.example.com"}}},
		{Name: "ok", Domains: d, UpstreamPort: 20001, Redirects: []Redirect{{From: "a.example.com", To: "b.example.com"}, {From: "a.example.com", To: "b.example.com"}}},
	}
	for i, s := range bad {
		_, err := m.Render(s)
		var ie *validate.InputError
		if !errors.As(err, &ie) {
			t.Errorf("case %d: want *InputError, got %v", i, err)
		}
	}
}

func TestStatusReportsVersionConfigAndSites(t *testing.T) {
	run := &fakeRunner{version: "nginx version: nginx/1.26.2"}
	m, dir := newManager(t, run, false)
	if err := m.Apply(context.Background(), Site{Name: "shop", Domains: []string{"shop.example.com", "www.shop.example.com"}, UpstreamPort: 20001, Certificate: "shop"}); err != nil {
		t.Fatal(err)
	}
	_ = dir

	st := m.Status(context.Background())

	if !st.Available || st.Version != "1.26.2" || !st.ConfigOK {
		t.Errorf("status = %+v", st)
	}
	if len(st.Sites) != 1 || st.Sites[0].Name != "shop" || !st.Sites[0].HTTPS {
		t.Fatalf("sites = %+v", st.Sites)
	}
	if got := strings.Join(st.Sites[0].Domains, ","); got != "shop.example.com,www.shop.example.com" {
		t.Errorf("domains = %q", got)
	}
}

func TestStatusShowsAFailingConfig(t *testing.T) {
	run := &fakeRunner{version: "nginx version: nginx/1.24.0", failOn: "test"}
	m, _ := newManager(t, run, false)

	st := m.Status(context.Background())

	if st.ConfigOK || !strings.Contains(st.ConfigOutput, "bad config") {
		t.Errorf("status = %+v", st)
	}
}

func TestReloadTestsFirst(t *testing.T) {
	run := &fakeRunner{failOn: "test"}
	m, _ := newManager(t, run, false)

	if err := m.Reload(context.Background()); err == nil {
		t.Fatal("reload must fail when the configuration is invalid")
	}
	for _, call := range run.calls {
		if strings.HasSuffix(call, "reload") {
			t.Errorf("must not reload a broken config: %v", run.calls)
		}
	}
}

func TestOnlyThePanelSiteProxiesTheTerminal(t *testing.T) {
	m, _ := newManager(t, nil, true)
	m.cfg.TerminalSocket = "/run/commypanel-terminal/terminal.sock"

	panel := Site{Name: "panel", Domains: []string{"panel.example.com"}, UpstreamPort: 8081}
	conf, err := m.Render(panel)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"location = /terminal {",
		"proxy_pass http://unix:/run/commypanel-terminal/terminal.sock;",
		`proxy_set_header Upgrade $http_upgrade;`,
		`proxy_set_header Connection "upgrade";`,
		"proxy_buffering off;",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("panel site is missing %q:\n%s", want, conf)
		}
	}
	// The terminal block comes before the catch-all location, and the app proxy is untouched.
	if strings.Index(conf, "location = /terminal") > strings.Index(conf, "location / {") {
		t.Error("the terminal location must come before location /")
	}

	// An app, even one whose custom directives try to reach the socket, gets no terminal.
	app := Site{Name: "shop", Domains: []string{"shop.example.com"}, UpstreamPort: 20001}
	conf, err = m.Render(app)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(conf, "terminal") {
		t.Errorf("an app site must not proxy the terminal:\n%s", conf)
	}
	app.Custom = "location = /terminal {\n proxy_pass http://unix:/run/commypanel-terminal/terminal.sock;\n}"
	if _, err := m.Render(app); err == nil {
		t.Error("custom directives must not be able to proxy to the terminal socket")
	}
}

func TestPanelSiteWithoutTerminalsHasNoTerminalLocation(t *testing.T) {
	m, _ := newManager(t, nil, true)
	conf, err := m.Render(Site{Name: "panel", Domains: []string{"panel.example.com"}, UpstreamPort: 8081})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(conf, "terminal") {
		t.Errorf("terminals are off, yet:\n%s", conf)
	}
}
