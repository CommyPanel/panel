package terminal

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"commypanel/agent/internal/validate"
)

// fakeShell echoes what it is given, upper-cased, and records resizes.
type fakeShell struct {
	in      chan []byte
	closed  chan struct{}
	once    sync.Once
	mu      sync.Mutex
	resizes [][2]int
	written bytes.Buffer
}

func newFakeShell() *fakeShell {
	return &fakeShell{in: make(chan []byte, 16), closed: make(chan struct{})}
}

func (s *fakeShell) Read(p []byte) (int, error) {
	select {
	case b := <-s.in:
		return copy(p, b), nil
	case <-s.closed:
		return 0, io.EOF
	}
}

func (s *fakeShell) Write(p []byte) (int, error) {
	s.mu.Lock()
	s.written.Write(p)
	s.mu.Unlock()
	s.in <- bytes.ToUpper(p)
	return len(p), nil
}

func (s *fakeShell) Close() error { s.once.Do(func() { close(s.closed) }); return nil }

func (s *fakeShell) Resize(cols, rows int) error {
	s.mu.Lock()
	s.resizes = append(s.resizes, [2]int{cols, rows})
	s.mu.Unlock()
	return nil
}

func (s *fakeShell) lastResize() [2]int {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.resizes) == 0 {
		return [2]int{}
	}
	return s.resizes[len(s.resizes)-1]
}

type harness struct {
	t       *testing.T
	srv     *Server
	addr    string
	tickets *Tickets
	shell   *fakeShell
	opened  chan [3]string
}

func newHarness(t *testing.T, limits Limits) *harness {
	t.Helper()
	h := &harness{t: t, tickets: NewTickets(30*time.Second, 16), shell: newFakeShell(), opened: make(chan [3]string, 8)}
	h.srv = &Server{
		Tickets: h.tickets, Limits: limits, tick: 20 * time.Millisecond,
		Open: func(_ context.Context, container string, cols, rows int) (Shell, error) {
			h.opened <- [3]string{container, itoa(cols), itoa(rows)}
			return h.shell, nil
		},
	}
	ts := httptest.NewServer(h.srv.Handler())
	t.Cleanup(ts.Close)
	h.addr = strings.TrimPrefix(ts.URL, "http://")
	return h
}

func itoa(n int) string { b, _ := json.Marshal(n); return string(b) }

var testLimits = Limits{MaxSessions: 4, Idle: time.Minute, Duration: time.Minute}

// open dials /terminal?ticket=... properly (dial() in ws_test.go uses a fixed path).
func (h *harness) open(ticket, origin, query string) (*peer, string) {
	h.t.Helper()
	return dialPath(h.t, h.addr, "/terminal?ticket="+ticket+query, origin)
}

func TestSessionPumpsInputOutputAndResizes(t *testing.T) {
	h := newHarness(t, testLimits)
	ticket, err := h.tickets.Issue("shop-1", "https://panel.example.com")
	if err != nil {
		t.Fatal(err)
	}

	p, status := h.open(ticket, "https://panel.example.com", "&cols=120&rows=40")
	if !strings.HasPrefix(status, "HTTP/1.1 101") {
		t.Fatalf("status = %q", status)
	}
	if got := <-h.opened; got != [3]string{"shop-1", "120", "40"} {
		t.Errorf("opened %v", got)
	}

	p.send(maskedFrame(true, opBinary, []byte("ls -la\n")))
	if op, data := p.read(); op != opBinary || string(data) != "LS -LA\n" {
		t.Errorf("output: %d %q", op, data)
	}

	p.send(maskedFrame(true, opText, []byte(`{"type":"resize","cols":100,"rows":30}`)))
	p.send(maskedFrame(true, opText, []byte(`{"type":"resize","cols":99999,"rows":-5}`)))
	p.send(maskedFrame(true, opText, []byte(`not json`)))
	p.send(maskedFrame(true, opBinary, []byte("x"))) // proves the earlier messages were handled
	p.read()
	if got := h.shell.lastResize(); got != [2]int{500, 1} {
		t.Errorf("a wild size must be clamped, got %v", got)
	}

	// The shell ends: the browser is told, then the socket closes.
	h.shell.Close()
	op, data := p.read()
	if op != opText || !strings.Contains(string(data), `"exit"`) {
		t.Errorf("exit message: %d %q", op, data)
	}
	if op, data := p.read(); op != opClose || binary.BigEndian.Uint16(data) != CloseNormal {
		t.Errorf("close: %d %v", op, data)
	}
}

func TestDefaultSizeWhenNoneGiven(t *testing.T) {
	h := newHarness(t, testLimits)
	ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	h.open(ticket, "https://panel.example.com", "")
	if got := <-h.opened; got != [3]string{"shop-1", "80", "24"} {
		t.Errorf("opened %v", got)
	}
}

func TestTicketIsSingleUseAndBoundToItsOrigin(t *testing.T) {
	h := newHarness(t, testLimits)

	ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	if _, status := h.open(ticket, "https://panel.example.com", ""); !strings.Contains(status, "101") {
		t.Fatalf("first use: %q", status)
	}
	if _, status := h.open(ticket, "https://panel.example.com", ""); !strings.Contains(status, " 403 ") {
		t.Errorf("second use: %q", status)
	}

	other, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	if _, status := h.open(other, "https://evil.example.com", ""); !strings.Contains(status, " 403 ") {
		t.Errorf("wrong origin: %q", status)
	}
	// A refused attempt still uses the ticket up, so it cannot be guessed at.
	if _, status := h.open(other, "https://panel.example.com", ""); !strings.Contains(status, " 403 ") {
		t.Errorf("ticket after a refused attempt: %q", status)
	}

	for _, bad := range []string{"", "x", strings.Repeat("A", 43), strings.Repeat("A", 100)} {
		if _, status := h.open(bad, "https://panel.example.com", ""); !strings.Contains(status, " 403 ") {
			t.Errorf("ticket %q: %q", bad, status)
		}
	}
}

func TestTheShellIsBoundToTheTicketsContainer(t *testing.T) {
	h := newHarness(t, testLimits)
	ticket, _ := h.tickets.Issue("blog-1", "https://panel.example.com")

	// Nothing in the request can name another container.
	h.open(ticket, "https://panel.example.com", "&container=shop-1&name=shop-1")
	if got := <-h.opened; got[0] != "blog-1" {
		t.Errorf("opened %v", got)
	}
}

func TestExpiredTicketsAreRefused(t *testing.T) {
	h := newHarness(t, testLimits)
	now := time.Now()
	h.tickets.now = func() time.Time { return now }
	ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	now = now.Add(31 * time.Second)

	if _, status := h.open(ticket, "https://panel.example.com", ""); !strings.Contains(status, " 403 ") {
		t.Errorf("expired: %q", status)
	}
}

func TestOnlyTheTerminalPathIsServed(t *testing.T) {
	h := newHarness(t, testLimits)
	for _, path := range []string{"/", "/v1/status", "/terminal/x", "/v1/docker/containers"} {
		if _, status := dialPath(t, h.addr, path, "https://panel.example.com"); !strings.Contains(status, " 404 ") {
			t.Errorf("%s: %q", path, status)
		}
	}
}

func TestSessionCap(t *testing.T) {
	limits := testLimits
	limits.MaxSessions = 1
	h := newHarness(t, limits)

	first, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	p, status := h.open(first, "https://panel.example.com", "")
	if !strings.Contains(status, "101") {
		t.Fatal(status)
	}
	<-h.opened

	second, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	if _, status := h.open(second, "https://panel.example.com", ""); !strings.Contains(status, " 503 ") {
		t.Errorf("second session: %q", status)
	}

	// Closing the first frees the slot.
	p.send(maskedFrame(true, opClose, binary.BigEndian.AppendUint16(nil, CloseNormal)))
	deadline := time.Now().Add(3 * time.Second)
	for h.srv.Active() != 0 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if h.srv.Active() != 0 {
		t.Fatal("the session did not end")
	}
	third, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	if _, status := h.open(third, "https://panel.example.com", ""); !strings.Contains(status, "101") {
		t.Errorf("after the first closed: %q", status)
	}
}

func TestIdleSessionsAreClosed(t *testing.T) {
	limits := testLimits
	limits.Idle = 100 * time.Millisecond
	h := newHarness(t, limits)
	ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	p, _ := h.open(ticket, "https://panel.example.com", "")

	op, data := p.read()
	if op != opClose || binary.BigEndian.Uint16(data) != ClosePolicy || !strings.Contains(string(data[2:]), "idle") {
		t.Errorf("got %d %q", op, data)
	}
}

func TestSessionsHaveATimeLimit(t *testing.T) {
	limits := testLimits
	limits.Duration = 150 * time.Millisecond
	h := newHarness(t, limits)
	ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
	p, _ := h.open(ticket, "https://panel.example.com", "")

	// Activity does not extend the total.
	stop := time.After(2 * time.Second)
	for {
		p.send(maskedFrame(true, opBinary, []byte("a")))
		op, data := p.read()
		if op == opClose {
			if !strings.Contains(string(data[2:]), "time limit") {
				t.Errorf("reason %q", data[2:])
			}
			return
		}
		select {
		case <-stop:
			t.Fatal("the session outlived its time limit")
		default:
		}
	}
}

func TestOpenFailuresAreShownToTheBrowser(t *testing.T) {
	for name, tc := range map[string]struct {
		err  error
		want string
	}{
		"input error":    {&validate.InputError{Msg: "the container is not running"}, "the container is not running"},
		"internal error": {errors.New("docker exploded at /var/run/docker.sock"), "The terminal could not be opened."},
	} {
		h := newHarness(t, testLimits)
		h.srv.Open = func(context.Context, string, int, int) (Shell, error) { return nil, tc.err }
		ticket, _ := h.tickets.Issue("shop-1", "https://panel.example.com")
		p, _ := h.open(ticket, "https://panel.example.com", "")

		op, data := p.read()
		if op != opText || !strings.Contains(string(data), tc.want) || strings.Contains(string(data), "docker.sock") {
			t.Errorf("%s: %d %q", name, op, data)
		}
		if op, _ := p.read(); op != opClose {
			t.Errorf("%s: no close after the error", name)
		}
	}
}

func TestTickets(t *testing.T) {
	now := time.Now()
	tk := NewTickets(30*time.Second, 2)
	tk.now = func() time.Time { return now }

	for _, bad := range [][2]string{
		{"Bad Name", "https://p.example.com"}, {"../x", "https://p.example.com"}, {"shop-1", ""},
		{"shop-1", "ftp://p.example.com"}, {"shop-1", "https://p.example.com/path"}, {"shop-1", "https://p.example.com/"},
		{"shop-1", "https://user@p.example.com"}, {"shop-1", "https://p.example.com?x=1"}, {"shop-1", "javascript:alert(1)"},
	} {
		if _, err := tk.Issue(bad[0], bad[1]); err == nil {
			t.Errorf("Issue(%q, %q) must fail", bad[0], bad[1])
		}
	}

	a, err := tk.Issue("shop-1", "https://p.example.com")
	if err != nil || len(a) != 43 {
		t.Fatalf("ticket %q: %v", a, err)
	}
	b, _ := tk.Issue("shop-2", "http://127.0.0.1:8443")
	if a == b {
		t.Error("tickets must be unique")
	}
	if _, err := tk.Issue("shop-3", "https://p.example.com"); !errors.Is(err, ErrTooManyTickets) {
		t.Errorf("third pending ticket: %v", err)
	}

	got, ok := tk.Redeem(a)
	if !ok || got != (Ticket{"shop-1", "https://p.example.com"}) {
		t.Errorf("redeem = %+v %v", got, ok)
	}
	if _, ok := tk.Redeem(a); ok {
		t.Error("a ticket works once")
	}

	now = now.Add(time.Minute)
	if _, ok := tk.Redeem(b); ok {
		t.Error("an expired ticket must be refused")
	}
	if tk.Pending() != 0 {
		t.Errorf("pending = %d after expiry", tk.Pending())
	}
	if _, err := tk.Issue("shop-3", "https://p.example.com"); err != nil {
		t.Errorf("expired tickets must not count towards the cap: %v", err)
	}
}

func TestTicketsAreKeptHashedNotAsIssued(t *testing.T) {
	tk := NewTickets(time.Minute, 4)
	token, _ := tk.Issue("shop-1", "https://p.example.com")
	for key := range tk.pending {
		if bytes.Contains(key[:], []byte(token[:8])) {
			t.Error("the ticket itself is stored")
		}
	}
}
