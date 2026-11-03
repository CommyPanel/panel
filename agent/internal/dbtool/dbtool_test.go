package dbtool

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/validate"
)

const (
	pw   = "abcdefghijklmnop1234"
	base = "https://commy.example.com:2053"
)

// fakeRunner stands in for Docker: it records what the sessions ask for.
type fakeRunner struct {
	mu       sync.Mutex
	pulled   []string
	ran      []docker.ToolSpec
	stopped  []string
	removed  []string
	left     []string
	runErr   error
	pullErr  error
	onRun    func(docker.ToolSpec)
	pullHold chan struct{}
}

func (f *fakeRunner) PullToolImage(ctx context.Context, image string) error {
	if f.pullHold != nil {
		select {
		case <-f.pullHold:
		case <-ctx.Done():
			return ctx.Err()
		}
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	f.pulled = append(f.pulled, image)
	return f.pullErr
}

func (f *fakeRunner) RunTool(_ context.Context, t docker.ToolSpec) error {
	f.mu.Lock()
	f.ran = append(f.ran, t)
	hook, err := f.onRun, f.runErr
	f.mu.Unlock()
	if hook != nil {
		hook(t)
	}
	return err
}

func (f *fakeRunner) Stop(_ context.Context, name string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.stopped = append(f.stopped, name)
	return nil
}

func (f *fakeRunner) Remove(_ context.Context, name string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.removed = append(f.removed, name)
	return nil
}

func (f *fakeRunner) ToolContainers(context.Context) ([]string, error) { return f.left, nil }

func (f *fakeRunner) removedNames() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.removed...)
}

// fakeClock is time the tests move by hand.
type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *fakeClock) Add(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.t = c.t.Add(d)
}

// rig is a manager with a fake Docker, a fake clock and a fake tool to talk to.
type rig struct {
	loginMu   sync.Mutex
	loginForm url.Values // what the agent posted to log in to phpMyAdmin
	m         *Manager
	runner    *fakeRunner
	clock     *fakeClock
	upstream  *httptest.Server
	seen      chan *http.Request
}

func newRig(t *testing.T, handler http.HandlerFunc) *rig {
	t.Helper()
	r := &rig{runner: &fakeRunner{}, clock: &fakeClock{t: time.Date(2026, 11, 3, 12, 0, 0, 0, time.UTC)}, seen: make(chan *http.Request, 16)}
	r.upstream = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		clone := req.Clone(req.Context())
		clone.Body = nil
		select {
		case r.seen <- clone:
		default:
		}
		if r.pmaLogin(w, req) {
			return
		}
		if handler != nil {
			handler(w, req)
			return
		}
		w.Header().Set("Content-Type", "text/html")
		_, _ = io.WriteString(w, "<html>tool</html>")
	}))
	t.Cleanup(r.upstream.Close)
	port, _ := strconv.Atoi(strings.TrimPrefix(r.upstream.URL, "http://127.0.0.1:"))
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	r.m = New(ctx, r.runner, Config{
		AllocatePort: func() (int, error) { return port, nil },
		Now:          r.clock.Now,
		MaxUpload:    1 << 10,
	})
	return r
}

// pmaLogin plays phpMyAdmin's login page, which is all the agent meets before it logs in. It
// returns true when it answered. Its cookies are marked Secure and carry the __Secure- prefix,
// as the real ones do, and the session cookie is sent twice with a new value the second time.
func (r *rig) pmaLogin(w http.ResponseWriter, req *http.Request) bool {
	if req.URL.Path != "/index.php" || req.URL.RawQuery != "" {
		return false
	}
	switch req.Method {
	case "GET":
		http.SetCookie(w, &http.Cookie{Name: "__Secure-phpMyAdmin_https", Value: "first", Path: "/", Secure: true})
		http.SetCookie(w, &http.Cookie{Name: "__Secure-pma_lang_https", Value: "en", Path: "/", Secure: true})
		_, _ = io.WriteString(w, `<form><input type="hidden" name="token" value="abc123"><input type="hidden" name="set_session" value="def456"></form>`)
	case "POST":
		_ = req.ParseForm()
		r.loginMu.Lock()
		r.loginForm = req.PostForm
		r.loginMu.Unlock()
		cookie, _ := req.Cookie("__Secure-phpMyAdmin_https")
		if req.PostForm.Get("pma_password") != pw || cookie == nil || cookie.Value != "first" {
			_, _ = io.WriteString(w, "login form again")
			return true
		}
		http.SetCookie(w, &http.Cookie{Name: "__Secure-phpMyAdmin_https", Value: "first", Path: "/", Secure: true})
		http.SetCookie(w, &http.Cookie{Name: "__Secure-phpMyAdmin_https", Value: "second", Path: "/", Secure: true})
		http.SetCookie(w, &http.Cookie{Name: "__Secure-pmaAuth-1_https", Value: "auth", Path: "/", Secure: true})
		http.SetCookie(w, &http.Cookie{Name: "laravel_session", Value: "not-the-tools", Path: "/"})
		w.Header().Set("Location", "/s/whatever/index.php?route=/")
		w.WriteHeader(http.StatusFound)
	}
	return true
}

func request(engine string) StartRequest {
	return StartRequest{Engine: engine, Server: "main", Database: "shop", User: "shop", Password: pw, PublicBase: base}
}

// start begins a session and waits until it is ready.
func (r *rig) start(t *testing.T, engine string) View {
	t.Helper()
	v, err := r.m.Start(request(engine))
	if err != nil {
		t.Fatalf("start: %v", err)
	}
	r.waitStage(t, v.ID, StageReady)
	return v
}

func (r *rig) waitStage(t *testing.T, id, stage string) View {
	t.Helper()
	for i := 0; i < 400; i++ {
		if v, ok := r.m.Status(id); ok && v.Stage == stage {
			return v
		}
		time.Sleep(10 * time.Millisecond)
	}
	v, _ := r.m.Status(id)
	t.Fatalf("session never reached %q, it is %+v", stage, v)
	return v
}

// browse redeems a ticket the way a browser does and returns the cookies it is given.
func (r *rig) browse(t *testing.T, id string) []*http.Cookie {
	t.Helper()
	ticket, err := r.m.Ticket(id)
	if err != nil {
		t.Fatalf("ticket: %v", err)
	}
	rec := httptest.NewRecorder()
	r.m.ServeHTTP(rec, httptest.NewRequest("GET", "/open?t="+ticket, nil))
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("open: %d %s", rec.Code, rec.Body)
	}
	return rec.Result().Cookies()
}

func get(r *rig, path string, cookies []*http.Cookie, mutate ...func(*http.Request)) *httptest.ResponseRecorder {
	req := httptest.NewRequest("GET", path, nil)
	for _, c := range cookies {
		req.AddCookie(c)
	}
	for _, f := range mutate {
		f(req)
	}
	rec := httptest.NewRecorder()
	r.m.ServeHTTP(rec, req)
	return rec
}

func TestASessionPullsStartsAndWaitsBeforeItIsReady(t *testing.T) {
	r := newRig(t, nil)
	r.runner.pullHold = make(chan struct{})

	v, err := r.m.Start(request("mariadb"))
	if err != nil {
		t.Fatal(err)
	}
	if v.Stage != StagePulling || v.Tool != "phpmyadmin" || v.Server != "main" || v.Database != "shop" {
		t.Fatalf("first view = %+v", v)
	}
	if _, err := r.m.Ticket(v.ID); err == nil {
		t.Error("a ticket was given for a session that is not ready")
	}

	close(r.runner.pullHold)
	r.waitStage(t, v.ID, StageReady)

	if len(r.runner.pulled) != 1 || r.runner.pulled[0] != docker.PhpMyAdminImage {
		t.Errorf("pulled %v", r.runner.pulled)
	}
	spec := r.runner.ran[0]
	if spec.Tool != "phpmyadmin" || spec.Server != "main" || spec.User != "shop" || spec.Password != pw || !strings.HasSuffix(spec.BaseURL, "/") || !strings.HasPrefix(spec.BaseURL, base+"/s/") {
		t.Errorf("spec = %+v", spec)
	}
}

func TestPostgresGetsAdminer(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "postgres")
	if v.Tool != "" && v.Tool != "adminer" {
		t.Errorf("tool = %s", v.Tool)
	}
	if got, _ := r.m.Status(v.ID); got.Tool != "adminer" {
		t.Errorf("tool = %s", got.Tool)
	}
}

func TestAFailureIsReportedAndLeavesNothingBehind(t *testing.T) {
	r := newRig(t, nil)
	r.runner.runErr = &validate.InputError{Msg: "the network is gone"}

	v, _ := r.m.Start(request("mariadb"))
	got := r.waitStage(t, v.ID, StageFailed)

	if got.Error != "the network is gone" {
		t.Errorf("error = %q", got.Error)
	}
	if len(r.runner.removedNames()) != 1 {
		t.Errorf("the container was not removed: %v", r.runner.removedNames())
	}
	if _, err := r.m.Ticket(v.ID); err == nil {
		t.Error("a failed session gave a ticket")
	}

	// What is not ours to explain is not passed on.
	r2 := newRig(t, nil)
	r2.runner.runErr = errors.New("dial unix /var/run/docker.sock: permission denied")
	v2, _ := r2.m.Start(request("mariadb"))
	if got := r2.waitStage(t, v2.ID, StageFailed); strings.Contains(got.Error, "docker.sock") {
		t.Errorf("internal detail leaked: %q", got.Error)
	}
}

func TestARequestIsCheckedBeforeAnythingStarts(t *testing.T) {
	r := newRig(t, nil)
	bad := map[string]func(*StartRequest){
		"an unknown engine":      func(s *StartRequest) { s.Engine = "oracle" },
		"a bad server":           func(s *StartRequest) { s.Server = "../x" },
		"a bad database":         func(s *StartRequest) { s.Database = "a;b" },
		"a root user":            func(s *StartRequest) { s.User = "Robert'); DROP" },
		"a short password":       func(s *StartRequest) { s.Password = "x" },
		"an http address":        func(s *StartRequest) { s.PublicBase = "http://commy.example.com:2053" },
		"an address with a path": func(s *StartRequest) { s.PublicBase = base + "/evil" },
		"an address with quotes": func(s *StartRequest) { s.PublicBase = `https://a"b` },
	}
	for name, mutate := range bad {
		req := request("mariadb")
		mutate(&req)
		if _, err := r.m.Start(req); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}
	if len(r.runner.ran) != 0 {
		t.Errorf("something was started: %v", r.runner.ran)
	}
}

func TestADatabaseHasOneSessionAtATimeAndThereIsALimit(t *testing.T) {
	r := newRig(t, nil)
	first := r.start(t, "mariadb")
	second := r.start(t, "mariadb")

	if _, ok := r.m.Status(first.ID); ok {
		t.Error("the first session is still there after a second was started for the same database")
	}
	if _, ok := r.m.Status(second.ID); !ok {
		t.Error("the second session is missing")
	}
	for i := 0; i < 50 && len(r.runner.removedNames()) < 1; i++ {
		time.Sleep(10 * time.Millisecond)
	}
	if len(r.runner.removedNames()) < 1 {
		t.Error("the replaced session's container was not removed")
	}

	for i := 0; i < 5; i++ {
		req := request("mariadb")
		req.Database = "db" + strconv.Itoa(i)
		if _, err := r.m.Start(req); err != nil {
			t.Fatalf("session %d: %v", i, err)
		}
	}
	over := request("mariadb")
	over.Database = "toomany"
	if _, err := r.m.Start(over); err == nil {
		t.Error("a seventh session was allowed")
	}
}

func TestATicketWorksOnceAndOnlyBriefly(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")

	ticket, _ := r.m.Ticket(v.ID)
	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusSeeOther {
		t.Fatalf("first use: %d", rec.Code)
	}
	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusNotFound {
		t.Errorf("second use: %d", rec.Code)
	}

	late, _ := r.m.Ticket(v.ID)
	r.clock.Add(2 * time.Minute)
	if rec := get(r, "/open?t="+late, nil); rec.Code != http.StatusNotFound {
		t.Errorf("an old ticket: %d", rec.Code)
	}
	for _, bad := range []string{"", "short", strings.Repeat("A", 43), strings.Repeat("A", 200)} {
		if rec := get(r, "/open?t="+bad, nil); rec.Code != http.StatusNotFound {
			t.Errorf("ticket %q: %d", bad, rec.Code)
		}
	}
	if rec := httptest.NewRecorder(); true {
		r.m.ServeHTTP(rec, httptest.NewRequest("POST", "/open?t="+ticket, nil))
		if rec.Code != http.StatusMethodNotAllowed {
			t.Errorf("POST /open: %d", rec.Code)
		}
	}
}

func TestOpeningSendsTheBrowserToTheDatabaseWithAnOwnCookie(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	ticket, _ := r.m.Ticket(v.ID)

	rec := get(r, "/open?t="+ticket, nil)

	if rec.Code != http.StatusSeeOther {
		t.Fatalf("status %d", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/s/"+v.ID+"/index.php?route=/database/structure&db=shop" {
		t.Errorf("location = %s", loc)
	}
	var c *http.Cookie
	for _, ck := range rec.Result().Cookies() {
		if ck.Name == "cpdbtool_"+v.ID {
			c = ck
		}
	}
	if c == nil {
		t.Fatalf("no session cookie in %v", rec.Result().Cookies())
	}
	if c.Name != "cpdbtool_"+v.ID || c.Path != "/s/"+v.ID+"/" || !c.Secure || !c.HttpOnly || c.SameSite != http.SameSiteStrictMode || len(c.Value) < 40 {
		t.Errorf("cookie = %+v", c)
	}
}

func TestTheProxyNeedsTheCookieOfThatSession(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	if rec := get(r, "/s/"+v.ID+"/page.php", cookies); rec.Code != 200 || !strings.Contains(rec.Body.String(), "tool") {
		t.Fatalf("with the cookie: %d %s", rec.Code, rec.Body)
	}
	for name, c := range map[string][]*http.Cookie{
		"no cookie":     nil,
		"a wrong value": {{Name: "cpdbtool_" + v.ID, Value: strings.Repeat("x", 43)}},
		"another name":  {{Name: "cpdbtool_other", Value: cookies[0].Value}},
	} {
		if rec := get(r, "/s/"+v.ID+"/page.php", c); rec.Code != http.StatusNotFound {
			t.Errorf("%s: %d", name, rec.Code)
		}
	}
	if rec := get(r, "/s/nosuchsession/page.php", cookies); rec.Code != http.StatusNotFound {
		t.Errorf("an unknown session: %d", rec.Code)
	}
	for _, path := range []string{"/", "/s", "/admin", "/open/x", "/s/"} {
		if rec := get(r, path, cookies); rec.Code != http.StatusNotFound && rec.Code != http.StatusBadRequest {
			t.Errorf("%s: %d", path, rec.Code)
		}
	}
}

func TestOneSessionsCookieDoesNotOpenAnotherSession(t *testing.T) {
	r := newRig(t, nil)
	a := r.start(t, "mariadb")
	other := request("mariadb")
	other.Database = "blog"
	b, _ := r.m.Start(other)
	r.waitStage(t, b.ID, StageReady)

	cookies := r.browse(t, a.ID)
	forged := []*http.Cookie{{Name: "cpdbtool_" + b.ID, Value: cookies[0].Value}}

	if rec := get(r, "/s/"+b.ID+"/", forged); rec.Code != http.StatusNotFound {
		t.Errorf("the cookie of one session opened another: %d", rec.Code)
	}
}

func TestOnlyTheToolsOwnCookiesReachTheToolAndNothingElseDoes(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	get(r, "/s/"+v.ID+"/index.php?route=/x&db=shop", cookies, func(q *http.Request) {
		q.AddCookie(&http.Cookie{Name: "commypanel_session", Value: "panel-secret"})
		q.AddCookie(&http.Cookie{Name: "XSRF-TOKEN", Value: "panel-token"})
		q.AddCookie(&http.Cookie{Name: "__Secure-phpMyAdmin_https", Value: "tool-session"})
		q.AddCookie(&http.Cookie{Name: "pma_lang_https", Value: "en"})
		q.Header.Set("Authorization", "Bearer panel")
		q.Header.Set("X-Forwarded-For", "6.6.6.6")
	})

	var seen *http.Request
	for len(r.seen) > 0 {
		seen = <-r.seen
	}
	if seen == nil {
		t.Fatal("the tool saw no request")
	}
	if seen.URL.Path != "/index.php" || seen.URL.RawQuery != "route=/x&db=shop" {
		t.Errorf("the tool was asked for %s?%s, the prefix must be gone", seen.URL.Path, seen.URL.RawQuery)
	}
	got := map[string]string{}
	for _, c := range seen.Cookies() {
		got[c.Name] = c.Value
	}
	// The tool's own cookies get through (the ones it made at login, and the ones the browser added) ...
	if got["pma_lang_https"] != "en" || got["__Secure-pmaAuth-1_https"] != "auth" || got["__Secure-phpMyAdmin_https"] == "" {
		t.Errorf("the tool's own cookies did not get through: %v", got)
	}
	// ... and nothing that is the panel's, or ours.
	for name := range got {
		if name == "commypanel_session" || name == "XSRF-TOKEN" || strings.HasPrefix(name, "cpdbtool_") {
			t.Errorf("the tool saw the cookie %s", name)
		}
	}
	if seen.Header.Get("Authorization") != "" || strings.Contains(seen.Header.Get("X-Forwarded-For"), "6.6.6.6") {
		t.Errorf("headers the tool saw: %v", seen.Header)
	}
}

func TestACookieTheToolSetsStaysInsideTheSessionAndOthersAreDropped(t *testing.T) {
	r := newRig(t, func(w http.ResponseWriter, _ *http.Request) {
		http.SetCookie(w, &http.Cookie{Name: "__Secure-phpMyAdmin_https", Value: "x", Path: "/", Domain: "example.com"})
		http.SetCookie(w, &http.Cookie{Name: "commypanel_session", Value: "planted", Path: "/"})
		http.SetCookie(w, &http.Cookie{Name: "XSRF-TOKEN", Value: "planted", Path: "/"})
		w.Header().Set("Server", "Apache")
		w.Header().Set("X-Powered-By", "PHP")
	})
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	rec := get(r, "/s/"+v.ID+"/page.php", cookies)

	set := rec.Result().Cookies()
	if len(set) != 1 || set[0].Name != "__Secure-phpMyAdmin_https" {
		t.Fatalf("cookies sent to the browser: %v", set)
	}
	c := set[0]
	if c.Path != "/s/"+v.ID+"/" || c.Domain != "" || !c.Secure || !c.HttpOnly || c.SameSite != http.SameSiteStrictMode {
		t.Errorf("cookie = %+v", c)
	}
	if rec.Header().Get("Server") != "" || rec.Header().Get("X-Powered-By") != "" {
		t.Errorf("headers: %v", rec.Header())
	}
	if rec.Header().Get("X-Frame-Options") != "DENY" || rec.Header().Get("X-Content-Type-Options") != "nosniff" {
		t.Errorf("headers: %v", rec.Header())
	}
}

func TestRedirectsStayInsideTheSession(t *testing.T) {
	var location string
	r := newRig(t, func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Location", location)
		w.WriteHeader(http.StatusFound)
	})
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)
	port := strings.TrimPrefix(r.upstream.URL, "http://")
	prefix := "/s/" + v.ID

	for given, want := range map[string]string{
		"index.php?a=1":                  "index.php?a=1",
		"/index.php?a=1":                 prefix + "/index.php?a=1",
		"http://" + port + "/index.php":  prefix + "/index.php",
		base + prefix + "/index.php?x=1": prefix + "/index.php?x=1",
		prefix + "/already":              prefix + "/already",
	} {
		location = given
		rec := get(r, prefix+"/", cookies)
		if rec.Code != http.StatusFound || rec.Header().Get("Location") != want {
			t.Errorf("redirect to %q became %d %q, want %q", given, rec.Code, rec.Header().Get("Location"), want)
		}
	}
	for _, away := range []string{"https://evil.example/", "//evil.example/x", "http://evil.example/", base + "/other",
		"javascript:alert(1)", "data:text/html,x", `\/evil.example/x`, `/\evil.example/x`, "http://127.0.0.1@evil.example/"} {
		location = away
		rec := get(r, prefix+"/", cookies)
		if rec.Code != http.StatusBadGateway || rec.Header().Get("Location") != "" {
			t.Errorf("redirect to %q became %d %q", away, rec.Code, rec.Header().Get("Location"))
		}
	}
}

func TestOnlyPlainRequestsOfAReasonableSizeAreProxied(t *testing.T) {
	r := newRig(t, func(w http.ResponseWriter, req *http.Request) {
		_, _ = io.Copy(io.Discard, req.Body)
	})
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	for _, method := range []string{"PUT", "DELETE", "PATCH", "OPTIONS", "TRACE", "CONNECT"} {
		req := httptest.NewRequest(method, "/s/"+v.ID+"/page.php", nil)
		for _, c := range cookies {
			req.AddCookie(c)
		}
		rec := httptest.NewRecorder()
		r.m.ServeHTTP(rec, req)
		if rec.Code != http.StatusMethodNotAllowed {
			t.Errorf("%s: %d", method, rec.Code)
		}
	}

	ws := httptest.NewRequest("GET", "/s/"+v.ID+"/ws", nil)
	ws.Header.Set("Upgrade", "websocket")
	for _, c := range cookies {
		ws.AddCookie(c)
	}
	if rec := httptest.NewRecorder(); true {
		r.m.ServeHTTP(rec, ws)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("an upgrade: %d", rec.Code)
		}
	}

	big := httptest.NewRequest("POST", "/s/"+v.ID+"/import.php", strings.NewReader(strings.Repeat("x", 4<<10)))
	for _, c := range cookies {
		big.AddCookie(c)
	}
	rec := httptest.NewRecorder()
	r.m.ServeHTTP(rec, big)
	if rec.Code != http.StatusRequestEntityTooLarge && rec.Code != http.StatusBadGateway {
		t.Errorf("an upload over the limit: %d", rec.Code)
	}
}

func TestWithoutTheSlashTheSessionAddressIsCompleted(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	rec := get(r, "/s/"+v.ID, cookies)
	if rec.Code != http.StatusPermanentRedirect || rec.Header().Get("Location") != "/s/"+v.ID+"/" {
		t.Errorf("%d %s", rec.Code, rec.Header().Get("Location"))
	}
}

func TestASessionClosesAfterFifteenMinutesWithoutUse(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	// Use keeps it open.
	r.clock.Add(14 * time.Minute)
	get(r, "/s/"+v.ID+"/page.php", cookies)
	r.clock.Add(14 * time.Minute)
	r.m.Reap()
	if _, ok := r.m.Status(v.ID); !ok {
		t.Fatal("a session in use was closed")
	}

	// Disuse closes it.
	r.clock.Add(2 * time.Minute)
	r.m.Reap()
	if _, ok := r.m.Status(v.ID); ok {
		t.Fatal("an idle session is still open")
	}
	for i := 0; i < 100 && len(r.runner.removedNames()) == 0; i++ {
		time.Sleep(10 * time.Millisecond)
	}
	if len(r.runner.removedNames()) != 1 {
		t.Errorf("the container was not removed: %v", r.runner.removedNames())
	}
	if rec := get(r, "/s/"+v.ID+"/page.php", cookies); rec.Code != http.StatusNotFound {
		t.Errorf("a closed session still answers: %d", rec.Code)
	}
}

func TestNoSessionLivesPastItsMaximumAge(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	for i := 0; i < 20; i++ { // in constant use for five hours
		r.clock.Add(15 * time.Minute)
		get(r, "/s/"+v.ID+"/page.php", cookies)
		r.m.Reap()
	}
	if _, ok := r.m.Status(v.ID); ok {
		t.Error("a session lived for five hours")
	}
}

func TestClosingASessionByHandRemovesItAtOnce(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	r.m.Stop(v.ID)
	r.m.Stop(v.ID) // closing twice is fine

	if rec := get(r, "/s/"+v.ID+"/", cookies); rec.Code != http.StatusNotFound {
		t.Errorf("%d", rec.Code)
	}
	for i := 0; i < 100 && len(r.runner.removedNames()) == 0; i++ {
		time.Sleep(10 * time.Millisecond)
	}
	if len(r.runner.removedNames()) != 1 {
		t.Errorf("removed %v", r.runner.removedNames())
	}
}

func TestToolsLeftBehindByACrashAreRemovedAtStartUp(t *testing.T) {
	r := newRig(t, nil)
	r.runner.left = []string{"dbtool-aaaaaaaaaaaa", "dbtool-bbbbbbbbbbbb"}

	r.m.Cleanup(context.Background())

	if len(r.runner.removed) != 2 || len(r.runner.stopped) != 2 {
		t.Errorf("stopped %v removed %v", r.runner.stopped, r.runner.removed)
	}
}

func TestAdminerIsLoggedInByTheAgentAndItsCookiesAreHandedOver(t *testing.T) {
	var loginForm url.Values
	r := newRig(t, func(w http.ResponseWriter, req *http.Request) {
		switch {
		case req.Method == "GET" && req.URL.RawQuery == "":
			http.SetCookie(w, &http.Cookie{Name: "adminer_sid", Value: "first", Path: "/", HttpOnly: true})
			http.SetCookie(w, &http.Cookie{Name: "adminer_key", Value: "key", Path: "/", HttpOnly: true})
			_, _ = io.WriteString(w, "login form")
		case req.Method == "POST":
			_ = req.ParseForm()
			loginForm = req.PostForm
			http.SetCookie(w, &http.Cookie{Name: "adminer_sid", Value: "logged-in", Path: "/", HttpOnly: true})
			w.Header().Set("Location", "?pgsql=db-main&username=shop&db=shop&ns=public")
			w.WriteHeader(http.StatusFound)
		default:
			_, _ = io.WriteString(w, "the database")
		}
	})
	v := r.start(t, "postgres")
	ticket, _ := r.m.Ticket(v.ID)

	rec := get(r, "/open?t="+ticket, nil)

	if rec.Code != http.StatusSeeOther || rec.Header().Get("Location") != "/s/"+v.ID+"/?pgsql=db-main&username=shop&db=shop&ns=public" {
		t.Fatalf("%d %s", rec.Code, rec.Header().Get("Location"))
	}
	if loginForm.Get("auth[username]") != "shop" || loginForm.Get("auth[password]") != pw || loginForm.Get("auth[server]") != "db-main" || loginForm.Get("auth[db]") != "shop" || loginForm.Get("auth[driver]") != "pgsql" {
		t.Errorf("login form = %v", loginForm)
	}
	names := map[string]string{}
	for _, c := range rec.Result().Cookies() {
		names[c.Name] = c.Value
		if c.Path != "/s/"+v.ID+"/" || !c.Secure || !c.HttpOnly {
			t.Errorf("cookie %+v", c)
		}
	}
	if names["adminer_sid"] != "logged-in" || names["adminer_key"] != "key" || names["cpdbtool_"+v.ID] == "" {
		t.Errorf("cookies = %v", names)
	}
	if strings.Contains(rec.Body.String(), pw) || strings.Contains(rec.Header().Get("Location"), pw) {
		t.Error("the password reached the browser")
	}
}

func TestAnAdminerThatRefusesTheLoginIsReportedAndTheTicketIsSpent(t *testing.T) {
	r := newRig(t, func(w http.ResponseWriter, req *http.Request) {
		_, _ = io.WriteString(w, "no luck")
	})
	v := r.start(t, "postgres")
	ticket, _ := r.m.Ticket(v.ID)

	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusBadGateway {
		t.Errorf("%d", rec.Code)
	}
	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusNotFound {
		t.Errorf("the ticket was not spent: %d", rec.Code)
	}
}

func TestPhpMyAdminIsLoggedInByTheAgentAndOnlyItsOwnCookiesAreHandedOver(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	ticket, _ := r.m.Ticket(v.ID)

	rec := get(r, "/open?t="+ticket, nil)

	if rec.Code != http.StatusSeeOther || rec.Header().Get("Location") != "/s/"+v.ID+"/index.php?route=/database/structure&db=shop" {
		t.Fatalf("%d %s", rec.Code, rec.Header().Get("Location"))
	}
	r.loginMu.Lock()
	form := r.loginForm
	r.loginMu.Unlock()
	if form.Get("pma_username") != "shop" || form.Get("pma_password") != pw || form.Get("token") != "abc123" || form.Get("set_session") != "def456" || form.Get("server") != "1" {
		t.Errorf("login form = %v", form)
	}
	names := map[string]string{}
	for _, c := range rec.Result().Cookies() {
		names[c.Name] = c.Value
		if c.Path != "/s/"+v.ID+"/" || !c.Secure || !c.HttpOnly || c.SameSite != http.SameSiteStrictMode {
			t.Errorf("cookie %+v", c)
		}
	}
	// The last value of the session cookie wins, the __Secure- names are kept, and
	// what the tool has no business setting is dropped.
	if names["__Secure-phpMyAdmin_https"] != "second" || names["__Secure-pmaAuth-1_https"] != "auth" || names["cpdbtool_"+v.ID] == "" {
		t.Errorf("cookies = %v", names)
	}
	if _, bad := names["laravel_session"]; bad {
		t.Error("a cookie that is not the tool's was handed to the browser")
	}
	if strings.Contains(rec.Body.String(), pw) || strings.Contains(rec.Header().Get("Location"), pw) {
		t.Error("the password reached the browser")
	}
}

func TestAPhpMyAdminThatRefusesTheLoginIsReportedAndTheTicketIsSpent(t *testing.T) {
	r := newRig(t, nil)
	v := r.start(t, "mariadb")
	// The database user's password changed after the session started: the login is refused.
	r.m.mu.Lock()
	r.m.sessions[v.ID].password = "WrongPassword1234567890"
	r.m.mu.Unlock()
	ticket, _ := r.m.Ticket(v.ID)

	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusBadGateway {
		t.Errorf("%d", rec.Code)
	}
	if rec := get(r, "/open?t="+ticket, nil); rec.Code != http.StatusNotFound {
		t.Errorf("the ticket was not spent: %d", rec.Code)
	}
}

func TestWhatTheToolSaysAboutTheWholeHostIsDropped(t *testing.T) {
	r := newRig(t, func(w http.ResponseWriter, _ *http.Request) {
		h := w.Header()
		h.Set("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
		h.Set("Clear-Site-Data", `"cookies"`)
		h.Set("Refresh", "0; url=https://evil.example/")
		h.Set("Content-Location", "https://evil.example/")
		h.Set("Link", "<https://evil.example/>; rel=preload")
		h.Set("Access-Control-Allow-Origin", "*")
		h.Set("Cache-Control", "public, max-age=600")
		h.Set("Referrer-Policy", "unsafe-url")
	})
	v := r.start(t, "mariadb")
	cookies := r.browse(t, v.ID)

	rec := get(r, "/s/"+v.ID+"/page.php", cookies)

	for _, name := range []string{"Strict-Transport-Security", "Clear-Site-Data", "Refresh", "Content-Location", "Link", "Access-Control-Allow-Origin"} {
		if rec.Header().Get(name) != "" {
			t.Errorf("%s reached the browser: %q", name, rec.Header().Get(name))
		}
	}
	// One value each: the tool's for what it chose, ours for the rest.
	if got := rec.Header().Values("Cache-Control"); len(got) != 1 || got[0] != "public, max-age=600" {
		t.Errorf("Cache-Control = %v", got)
	}
	if got := rec.Header().Values("X-Content-Type-Options"); len(got) != 1 || got[0] != "nosniff" {
		t.Errorf("X-Content-Type-Options = %v", got)
	}
	if got := rec.Header().Values("Referrer-Policy"); len(got) != 1 || got[0] != "no-referrer" {
		t.Errorf("Referrer-Policy = %v", got)
	}
	if rec.Header().Get("Content-Security-Policy") != "frame-ancestors 'none'" {
		t.Errorf("CSP = %q", rec.Header().Get("Content-Security-Policy"))
	}
}

func TestStoppingTheAgentClosesEveryToolAtOnce(t *testing.T) {
	r := newRig(t, nil)
	a := r.start(t, "mariadb")
	other := request("mariadb")
	other.Database = "blog"
	b, _ := r.m.Start(other)
	r.waitStage(t, b.ID, StageReady)
	r.runner.left = []string{"dbtool-straggler00"}

	r.m.Shutdown()

	if _, ok := r.m.Status(a.ID); ok {
		t.Error("a session survived the shutdown")
	}
	removed := strings.Join(r.runner.removedNames(), " ")
	if len(r.runner.removedNames()) != 3 || !strings.Contains(removed, "dbtool-straggler00") {
		t.Errorf("removed %v", r.runner.removedNames())
	}
}
