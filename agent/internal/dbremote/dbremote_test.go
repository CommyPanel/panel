package dbremote

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"net/netip"
	"strconv"
	"sync"
	"testing"
	"time"

	"commypanel/agent/internal/validate"
)

// ---- fakes ----

type fakeClock struct {
	mu     sync.Mutex
	now    time.Time
	timers []*fakeTimer
}
type fakeTimer struct {
	at      time.Time
	f       func()
	stopped bool
}

func newClock() *fakeClock { return &fakeClock{now: time.Unix(1_800_000_000, 0)} }
func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}
func (c *fakeClock) AfterFunc(d time.Duration, f func()) func() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	t := &fakeTimer{at: c.now.Add(d), f: f}
	c.timers = append(c.timers, t)
	return func() bool { c.mu.Lock(); defer c.mu.Unlock(); was := !t.stopped; t.stopped = true; return was }
}

// Advance moves the clock and fires the timers that are due.
func (c *fakeClock) Advance(d time.Duration) { c.set(d, true) }

// Skip moves the clock without firing timers (a timer that is late).
func (c *fakeClock) Skip(d time.Duration) { c.set(d, false) }

func (c *fakeClock) set(d time.Duration, fire bool) {
	c.mu.Lock()
	c.now = c.now.Add(d)
	var due []*fakeTimer
	for _, t := range c.timers {
		if fire && !t.stopped && !t.at.After(c.now) {
			t.stopped = true
			due = append(due, t)
		}
	}
	c.mu.Unlock()
	for _, t := range due {
		t.f()
	}
}

type fakeDocker struct {
	mu       sync.Mutex
	checkErr error
	addrErr  error
	checked  []string
}

func (f *fakeDocker) CheckDatabaseServer(_ context.Context, server, engine string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.checked = append(f.checked, server+"/"+engine)
	return f.checkErr
}
func (f *fakeDocker) DatabaseAddress(context.Context, string) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return "172.30.0.2", f.addrErr
}

// backend is an in-process database that speaks just enough of each protocol.
type backend struct {
	t      *testing.T
	ln     net.Listener
	engine string
	sslAns byte // postgres: the answer to SSLRequest

	mu        sync.Mutex
	conns     int
	got       [][]byte // what each connection sent (after the protocol step)
	first     [][]byte // the protocol step bytes
	dialed    []string
	wg        sync.WaitGroup
	handshake []byte
}

func newBackend(t *testing.T, engine string) *backend {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	b := &backend{t: t, ln: ln, engine: engine, sslAns: 'S',
		handshake: mysqlPacket(0, append([]byte{10}, bytes.Repeat([]byte("8"), 40)...))}
	t.Cleanup(func() { ln.Close(); b.wg.Wait() })
	b.wg.Add(1)
	go func() {
		defer b.wg.Done()
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			b.mu.Lock()
			b.conns++
			b.mu.Unlock()
			b.wg.Add(1)
			go func() { defer b.wg.Done(); b.serve(c) }()
		}
	}()
	return b
}

func (b *backend) serve(c net.Conn) {
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(10 * time.Second))
	var first []byte
	if b.engine == "postgres" {
		head := make([]byte, 8)
		if _, err := io.ReadFull(c, head); err != nil {
			return
		}
		first = head
		if head[3] == 16 { // cancel request: 16 bytes, then the server hangs up
			rest := make([]byte, 8)
			io.ReadFull(c, rest)
			b.record(append(first, rest...), nil)
			return
		}
		if _, err := c.Write([]byte{b.sslAns}); err != nil {
			return
		}
	} else {
		if _, err := c.Write(b.handshake); err != nil {
			return
		}
		first = make([]byte, 36)
		if _, err := io.ReadFull(c, first); err != nil {
			return
		}
	}
	// After the protocol step: echo upper-cased, remembering what arrived.
	buf := make([]byte, 4096)
	var all []byte
	for {
		n, err := c.Read(buf)
		if n > 0 {
			all = append(all, buf[:n]...)
			b.record(first, all)
			c.Write(upper(buf[:n]))
		}
		if err != nil {
			return
		}
	}
}

func (b *backend) record(first, all []byte) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.first = append(b.first, first)
	b.got = append(b.got, all)
}

func (b *backend) count() int { b.mu.Lock(); defer b.mu.Unlock(); return b.conns }

type rig struct {
	t     *testing.T
	m     *Manager
	d     *fakeDocker
	clock *fakeClock
	be    *backend
}

func freePort(t *testing.T) int {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	return ln.Addr().(*net.TCPAddr).Port
}

func newRig(t *testing.T, engine string, tweak func(*Config)) *rig {
	t.Helper()
	r := &rig{t: t, d: &fakeDocker{}, clock: newClock(), be: newBackend(t, engine)}
	cfg := Config{
		Ports: PortRange{1024, 65535}, Now: r.clock.Now, AfterFunc: r.clock.AfterFunc, allowLoopback: true,
		Listen: func(port int) (net.Listener, error) { return net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(port)) },
		Dial: func(ctx context.Context, addr string) (net.Conn, error) {
			r.be.mu.Lock()
			r.be.dialed = append(r.be.dialed, addr)
			r.be.mu.Unlock()
			return (&net.Dialer{}).DialContext(ctx, "tcp", r.be.ln.Addr().String())
		},
		Handshake: 2 * time.Second,
	}
	if tweak != nil {
		tweak(&cfg)
	}
	r.m = New(cfg, r.d)
	t.Cleanup(r.m.Shutdown)
	return r
}

func (r *rig) open(server, engine string, allow []string, expires int64) (int, error) {
	r.t.Helper()
	port := freePort(r.t)
	return r.openOn(server, engine, port, allow, expires)
}

func (r *rig) openOn(server, engine string, port int, allow []string, expires int64) (int, error) {
	return r.m.Open(context.Background(), OpenRequest{Server: server, Engine: engine, Port: port, Allow: allow, ExpiresAt: expires})
}

func dial(t *testing.T, port int) net.Conn {
	t.Helper()
	c, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	return c
}

// closedWithoutBytes asserts the server hangs up having sent nothing.
func closedWithoutBytes(t *testing.T, c net.Conn, why string) {
	t.Helper()
	n, err := c.Read(make([]byte, 16))
	if n != 0 || err == nil {
		t.Errorf("%s: read %d bytes, err %v; want the connection closed with no bytes", why, n, err)
	}
	var ne net.Error
	if errors.As(err, &ne) && ne.Timeout() {
		t.Errorf("%s: connection was left open", why)
	}
}

func eventually(t *testing.T, what string, cond func() bool) {
	t.Helper()
	for i := 0; i < 400; i++ {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

var local = []string{"127.0.0.1/32"}

// ---- validators ----

func TestParseAllow(t *testing.T) {
	ok := map[string]string{
		"198.51.100.7":    "198.51.100.7/32",
		"203.0.113.0/24":  "203.0.113.0/24",
		"2001:db8::/48":   "2001:db8::/48",
		"2001:db8::1":     "2001:db8::1/128",
		"10.0.0.0/16":     "10.0.0.0/16",
		"2001:db8:1::/64": "2001:db8:1::/64",
	}
	for in, want := range ok {
		got, err := ParseAllow([]string{in})
		if err != nil || got[0].String() != want {
			t.Errorf("%s: %v %v", in, got, err)
		}
	}
	bad := []string{"", "0.0.0.0/0", "10.0.0.0/15", "10.0.0.0/8", "::/0", "2001:db8::/47", "2000::/3", "0.0.0.0", "::",
		"127.0.0.1", "127.0.0.0/16", "::1", "224.0.0.1", "ff02::1", "169.254.1.1", "fe80::1", "fe80::/64", "::ffff:10.0.0.1",
		"::ffff:10.0.0.0/112", "fe80::1%eth0", "example.com", "1.2.3.4/33", "1.2.3.4/-1", "1.2.3", "1.2.3.4/24/1", "1.2.3.4 "}
	for _, in := range bad {
		if _, err := ParseAllow([]string{in}); err == nil {
			t.Errorf("%q was accepted", in)
		}
	}
	if _, err := ParseAllow(nil); err == nil {
		t.Error("an empty list was accepted")
	}
	many := make([]string, 21)
	for i := range many {
		many[i] = "198.51.100." + strconv.Itoa(i+1)
	}
	if _, err := ParseAllow(many[:20]); err != nil {
		t.Errorf("20 entries: %v", err)
	}
	if _, err := ParseAllow(many); err == nil {
		t.Error("21 entries were accepted")
	}
}

func TestParsePortRange(t *testing.T) {
	if r, err := ParsePortRange("10000-19999"); err != nil || r != (PortRange{10000, 19999}) {
		t.Errorf("%v %v", r, err)
	}
	for _, s := range []string{"", "10000", "a-b", "19999-10000", "80-90", "1000-70000", "-"} {
		if _, err := ParsePortRange(s); err == nil {
			t.Errorf("%q accepted", s)
		}
	}
}

func TestOpenValidation(t *testing.T) {
	r := newRig(t, "postgres", func(c *Config) { c.Ports = PortRange{10000, 19999}; c.Reserved = []int{15000} })
	now := r.clock.Now().Unix()
	allow := []string{"203.0.113.0/24"}
	bad := map[string]OpenRequest{
		"port below range":  {Server: "main", Engine: "postgres", Port: 9999, Allow: allow},
		"port above range":  {Server: "main", Engine: "postgres", Port: 20000, Allow: allow},
		"port zero":         {Server: "main", Engine: "postgres", Port: 0, Allow: allow},
		"reserved by agent": {Server: "main", Engine: "postgres", Port: 15000, Allow: allow},
		"bad slug":          {Server: "../x", Engine: "postgres", Port: 10001, Allow: allow},
		"uppercase slug":    {Server: "Main", Engine: "postgres", Port: 10001, Allow: allow},
		"bad engine":        {Server: "main", Engine: "oracle", Port: 10001, Allow: allow},
		"no allow":          {Server: "main", Engine: "postgres", Port: 10001},
		"wide allow":        {Server: "main", Engine: "postgres", Port: 10001, Allow: []string{"0.0.0.0/0"}},
		"in the past":       {Server: "main", Engine: "postgres", Port: 10001, Allow: allow, ExpiresAt: now - 1},
		"now":               {Server: "main", Engine: "postgres", Port: 10001, Allow: allow, ExpiresAt: now},
		"too far":           {Server: "main", Engine: "postgres", Port: 10001, Allow: allow, ExpiresAt: now + 401*86400},
		"negative":          {Server: "main", Engine: "postgres", Port: 10001, Allow: allow, ExpiresAt: -5},
	}
	for name, req := range bad {
		_, err := r.m.Open(context.Background(), req)
		var in *validate.InputError
		if !errors.As(err, &in) {
			t.Errorf("%s: err = %v, want an input error", name, err)
		}
	}
	if len(r.m.List()) != 0 {
		t.Error("something was opened by a rejected request")
	}
	// The ports that are never offered, even if the range includes them.
	wide := newRig(t, "postgres", func(c *Config) { c.Ports = PortRange{1024, 65535} })
	for _, p := range []int{22, 80, 443, 2053, 8443} {
		if err := wide.m.CheckPort(p); err == nil {
			t.Errorf("port %d accepted", p)
		}
	}
	if err := wide.m.CheckPort(8444); err != nil {
		t.Error(err)
	}
	// Docker's answer (not a database server of this engine) is passed on and nothing is opened.
	r.d.checkErr = &validate.InputError{Msg: "not a database server"}
	if _, err := r.openOn("main", "postgres", 10002, allow, 0); err == nil {
		t.Error("a container that is not a database server was opened")
	}
	// 400 days is allowed, and a far-off expiry is reported back.
	r.d.checkErr = nil
	if _, err := r.openOn("main", "postgres", 10003, allow, now+399*86400); err != nil {
		t.Errorf("399 days: %v", err)
	}
	if got := r.m.List()[0].ExpiresAt; got != now+399*86400 {
		t.Errorf("expires_at = %d", got)
	}
}

// ---- allow list and refusal ----

func TestSourceMatching(t *testing.T) {
	l := &listener{}
	allow, _ := ParseAllow([]string{"198.51.100.7", "203.0.113.0/24", "2001:db8::/48", "192.0.2.128/25"})
	l.allow = allow
	cases := map[string]bool{
		"198.51.100.7": true, "198.51.100.8": false, "198.51.100.6": false,
		"203.0.113.0": true, "203.0.113.255": true, "203.0.114.0": false, "203.0.112.255": false,
		"2001:db8::1": true, "2001:db8:0:ffff::1": true, "2001:db8:1::1": false, "2001:db9::1": false,
		"192.0.2.127": false, "192.0.2.128": true, "192.0.2.255": true,
		"::ffff:198.51.100.7": false, // matching happens after unmapping, see TestSourceIsUnmapped
	}
	for in, want := range cases {
		if got := l.allowedLocked(netip.MustParseAddr(in)); got != want {
			t.Errorf("%s: allowed = %v, want %v", in, got, want)
		}
	}
}

type addrConn struct {
	net.Conn
	addr net.Addr
}

func (a addrConn) RemoteAddr() net.Addr { return a.addr }

func TestSourceIsUnmapped(t *testing.T) {
	c1, c2 := net.Pipe()
	defer c1.Close()
	defer c2.Close()
	for in, want := range map[string]string{
		"[::ffff:198.51.100.7]:5555": "198.51.100.7",
		"198.51.100.7:5555":          "198.51.100.7",
		"[2001:db8::1]:5555":         "2001:db8::1",
	} {
		ap := netip.MustParseAddrPort(in)
		got, ok := sourceOf(addrConn{c1, net.TCPAddrFromAddrPort(ap)})
		if !ok || got.String() != want {
			t.Errorf("%s -> %v %v, want %s", in, got, ok, want)
		}
	}
	if _, ok := sourceOf(addrConn{c1, &net.UnixAddr{Name: "x", Net: "unix"}}); ok {
		t.Error("a non-IP source was accepted")
	}
	// A v4-mapped source matches a plain IPv4 rule.
	l := &listener{}
	l.allow, _ = ParseAllow([]string{"198.51.100.0/24"})
	src, _ := sourceOf(addrConn{c1, net.TCPAddrFromAddrPort(netip.MustParseAddrPort("[::ffff:198.51.100.7]:1"))})
	if !l.allowedLocked(src) {
		t.Error("a v4-mapped source did not match its IPv4 rule")
	}
}

func TestRefusedSourceGetsNoBytesAndNoBackendConnection(t *testing.T) {
	for _, engine := range []string{"postgres", "mysql"} {
		r := newRig(t, engine, nil)
		port, err := r.open("main", engine, []string{"203.0.113.0/24"}, 0)
		if err != nil {
			t.Fatal(err)
		}
		c := dial(t, port)
		if engine == "postgres" {
			c.Write(pgMsg(8, 80877103))
		}
		closedWithoutBytes(t, c, engine+" refused source")
		time.Sleep(50 * time.Millisecond)
		if r.be.count() != 0 || len(r.be.dialed) != 0 {
			t.Errorf("%s: the backend was contacted for a refused source", engine)
		}
		eventually(t, "refusal counted", func() bool {
			l := r.m.byServer["main"]
			l.mu.Lock()
			defer l.mu.Unlock()
			return l.refused == 1 && l.total == 0
		})
	}
}

// ---- end to end ----

func TestPostgresRelaysBytesUnchangedAfterSSLRequest(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, err := r.open("main", "postgres", local, 0)
	if err != nil {
		t.Fatal(err)
	}
	c := dial(t, port)
	ssl := pgMsg(8, 80877103)
	c.Write(ssl)
	one := make([]byte, 1)
	if _, err := io.ReadFull(c, one); err != nil || one[0] != 'S' {
		t.Fatalf("answer = %q, %v", one, err)
	}
	payload := bytes.Repeat([]byte("\x16\x03\x01tls-bytes-abc\x00\xff"), 3000)
	go c.Write(payload)
	echo := make([]byte, len(payload))
	if _, err := io.ReadFull(c, echo); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(echo, upper(payload)) {
		t.Error("the answer was changed on the way back")
	}
	r.be.mu.Lock()
	defer r.be.mu.Unlock()
	if !bytes.Equal(r.be.first[0], ssl) || !bytes.Equal(r.be.got[len(r.be.got)-1], payload) {
		t.Error("the backend did not receive the request and payload unchanged")
	}
	if len(r.be.dialed) != 1 || r.be.dialed[0] != "172.30.0.2:5432" {
		t.Errorf("dialed %v", r.be.dialed)
	}
}

func TestPostgresGSSENCIsDeclinedThenSSLIsRelayed(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, _ := r.open("main", "postgres", local, 0)
	c := dial(t, port)
	c.Write(pgMsg(8, 80877104))
	one := make([]byte, 1)
	if _, err := io.ReadFull(c, one); err != nil || one[0] != 'N' {
		t.Fatalf("GSSENC answer = %q %v", one, err)
	}
	c.Write(pgMsg(8, 80877103))
	if _, err := io.ReadFull(c, one); err != nil || one[0] != 'S' {
		t.Fatalf("SSL answer = %q %v", one, err)
	}
	c.Write([]byte("abc"))
	got := make([]byte, 3)
	if _, err := io.ReadFull(c, got); err != nil || string(got) != "ABC" {
		t.Fatalf("echo %q %v", got, err)
	}
	if r.be.count() != 1 {
		t.Error("the GSSENC request reached the backend")
	}
}

func TestPostgresCancelRequestIsPassedThrough(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, _ := r.open("main", "postgres", local, 0)
	c := dial(t, port)
	cancel := pgMsg(16, 80877102, 0, 0, 0, 7, 1, 2, 3, 4)
	c.Write(cancel)
	eventually(t, "backend got the cancel request", func() bool {
		r.be.mu.Lock()
		defer r.be.mu.Unlock()
		return len(r.be.first) == 1 && bytes.Equal(r.be.first[0], cancel)
	})
}

func TestPostgresPlainClientsAreRefused(t *testing.T) {
	startup := pgMsg(41, 196608, append([]byte("user\x00bob\x00database\x00x\x00"), make([]byte, 12)...)...)
	for name, send := range map[string][]byte{
		"plain startup":   startup,
		"garbage":         bytes.Repeat([]byte{0xff}, 40),
		"gss then plain":  append(pgMsg(8, 80877104), startup...),
		"short and close": {0, 0, 0},
	} {
		r := newRig(t, "postgres", nil)
		port, _ := r.open("main", "postgres", local, 0)
		c := dial(t, port)
		c.Write(send)
		if name == "gss then plain" {
			one := make([]byte, 1)
			io.ReadFull(c, one) // the 'N'
		}
		if name == "short and close" {
			c.(*net.TCPConn).CloseWrite()
		}
		closedWithoutBytes(t, c, name)
		time.Sleep(30 * time.Millisecond)
		r.be.mu.Lock()
		leaked := len(r.be.got) > 0
		for _, f := range r.be.first {
			if bytes.Contains(f, []byte("bob")) {
				leaked = true
			}
		}
		r.be.mu.Unlock()
		if leaked {
			t.Errorf("%s: the login reached the backend", name)
		}
	}
}

func TestPostgresBackendWithoutTLSClosesTheClient(t *testing.T) {
	r := newRig(t, "postgres", nil)
	r.be.sslAns = 'N'
	port, _ := r.open("main", "postgres", local, 0)
	c := dial(t, port)
	c.Write(pgMsg(8, 80877103))
	closedWithoutBytes(t, c, "backend answered N")
}

func TestMySQLRelaysHandshakeThenBytesUnchanged(t *testing.T) {
	r := newRig(t, "mysql", nil)
	port, _ := r.open("main", "mysql", local, 0)
	c := dial(t, port)
	hs := make([]byte, len(r.be.handshake))
	if _, err := io.ReadFull(c, hs); err != nil || !bytes.Equal(hs, r.be.handshake) {
		t.Fatalf("handshake %x %v", hs, err)
	}
	req := mysqlPacket(1, sslRequestPayload(0x0000f9a5|0x800))
	c.Write(req)
	payload := bytes.Repeat([]byte("\x16\x03\x03hello-tls"), 2000)
	go c.Write(payload)
	echo := make([]byte, len(payload))
	if _, err := io.ReadFull(c, echo); err != nil || !bytes.Equal(echo, upper(payload)) {
		t.Fatalf("echo err %v", err)
	}
	r.be.mu.Lock()
	defer r.be.mu.Unlock()
	if !bytes.Equal(r.be.first[0], req) || !bytes.Equal(r.be.got[len(r.be.got)-1], payload) {
		t.Error("backend got different bytes")
	}
	if r.be.dialed[0] != "172.30.0.2:3306" {
		t.Errorf("dialed %v", r.be.dialed)
	}
}

func TestMySQLPlainLoginIsRefused(t *testing.T) {
	for name, pkt := range map[string][]byte{
		"login without ssl": mysqlPacket(1, append(sslRequestPayload(0xf7a5), bytes.Repeat([]byte("bob\x00"), 10)...)),
		"short":             mysqlPacket(1, make([]byte, 20)),
		"flag missing":      mysqlPacket(1, sslRequestPayload(0xf7a5)),
		"wrong sequence":    mysqlPacket(3, sslRequestPayload(0x800)),
	} {
		r := newRig(t, "mysql", nil)
		port, _ := r.open("main", "mysql", local, 0)
		c := dial(t, port)
		hs := make([]byte, len(r.be.handshake))
		io.ReadFull(c, hs)
		c.Write(pkt)
		closedWithoutBytes(t, c, name)
		time.Sleep(30 * time.Millisecond)
		r.be.mu.Lock()
		if len(r.be.first) != 0 {
			t.Errorf("%s: forwarded to the backend", name)
		}
		r.be.mu.Unlock()
	}
}

func TestSilentClientIsClosedAfterTheHandshakeDeadline(t *testing.T) {
	r := newRig(t, "postgres", func(c *Config) { c.Handshake = 150 * time.Millisecond })
	port, _ := r.open("main", "postgres", local, 0)
	c := dial(t, port)
	start := time.Now()
	closedWithoutBytes(t, c, "silent client")
	if time.Since(start) > 3*time.Second {
		t.Error("deadline not enforced")
	}
}

func TestIdleSessionsAreNotClosedAfterTheHandshake(t *testing.T) {
	r := newRig(t, "postgres", func(c *Config) { c.Handshake = 150 * time.Millisecond })
	port, _ := r.open("main", "postgres", local, 0)
	c := dial(t, port)
	c.Write(pgMsg(8, 80877103))
	io.ReadFull(c, make([]byte, 1))
	time.Sleep(400 * time.Millisecond)
	c.Write([]byte("hi"))
	got := make([]byte, 2)
	if _, err := io.ReadFull(c, got); err != nil || string(got) != "HI" {
		t.Fatalf("an idle session was cut: %q %v", got, err)
	}
}

func TestBackendUnreachableClosesTheClient(t *testing.T) {
	for name, tweak := range map[string]func(*rig){
		"no address": func(r *rig) { r.d.setAddrErr(errors.New("db-main is not running")) },
		"dial fails": func(r *rig) { r.be.ln.Close() },
	} {
		r := newRig(t, "postgres", nil)
		port, _ := r.open("main", "postgres", local, 0)
		tweak(r)
		c := dial(t, port)
		c.Write(pgMsg(8, 80877103))
		closedWithoutBytes(t, c, name)
	}
}

// ---- lifecycle ----

func TestExpiryClosesActiveConnectionsAndTheListener(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port := freePort(t)
	expires := r.clock.Now().Add(time.Hour).Unix()
	if _, err := r.openOn("main", "postgres", port, local, expires); err != nil {
		t.Fatal(err)
	}
	c := establishedPG(t, port)
	// Activity does not postpone it.
	for i := 0; i < 3; i++ {
		r.clock.Advance(15 * time.Minute)
		c.Write([]byte("x"))
		io.ReadFull(c, make([]byte, 1))
	}
	r.clock.Advance(15*time.Minute + time.Second)
	if _, err := c.Read(make([]byte, 1)); err == nil {
		t.Fatal("an active connection survived the expiry")
	}
	if len(r.m.List()) != 0 {
		t.Error("the listener is still listed")
	}
	if cn, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), time.Second); err == nil {
		cn.Close()
		t.Error("the port still accepts connections")
	}
}

func establishedPG(t *testing.T, port int) net.Conn {
	t.Helper()
	c := dial(t, port)
	c.Write(pgMsg(8, 80877103))
	one := make([]byte, 1)
	if _, err := io.ReadFull(c, one); err != nil || one[0] != 'S' {
		t.Fatalf("not established: %q %v", one, err)
	}
	return c
}

func TestExpiryIsAlsoCheckedOnEveryAccept(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port := freePort(t)
	if _, err := r.openOn("main", "postgres", port, local, r.clock.Now().Add(time.Hour).Unix()); err != nil {
		t.Fatal(err)
	}
	r.clock.Skip(2 * time.Hour) // the timer is late
	c := dial(t, port)
	c.Write(pgMsg(8, 80877103))
	closedWithoutBytes(t, c, "late expiry")
	eventually(t, "listener removed", func() bool { return len(r.m.List()) == 0 })
	if r.be.count() != 0 {
		t.Error("the backend was contacted after the expiry")
	}
}

func TestUpdateInPlaceKeepsOnlyConnectionsThatStillMatch(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port := freePort(t)
	if _, err := r.openOn("main", "postgres", port, local, 0); err != nil {
		t.Fatal(err)
	}
	c := establishedPG(t, port)
	// Still allowed, now with an expiry: kept.
	exp := r.clock.Now().Add(time.Hour).Unix()
	if got, err := r.openOn("main", "postgres", port, []string{"127.0.0.0/24", "203.0.113.0/24"}, exp); err != nil || got != port {
		t.Fatalf("update: %d %v", got, err)
	}
	c.Write([]byte("ok"))
	got := make([]byte, 2)
	if _, err := io.ReadFull(c, got); err != nil || string(got) != "OK" {
		t.Fatalf("a connection that still matches was dropped: %v", err)
	}
	if l := r.m.List(); len(l) != 1 || l[0].ExpiresAt != exp || len(l[0].Allow) != 2 || l[0].Connections != 1 {
		t.Errorf("list = %+v", l)
	}
	// The old expiry timer does not fire any more, the new one does.
	// Source no longer allowed: closed.
	if _, err := r.openOn("main", "postgres", port, []string{"203.0.113.0/24"}, exp); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Read(make([]byte, 1)); err == nil {
		t.Error("a connection whose source no longer matches survived")
	}
	closedWithoutBytes(t, dial(t, port), "new connection from a removed source")
}

func TestPortChangeClosesTheOldListenerAndItsConnections(t *testing.T) {
	r := newRig(t, "postgres", nil)
	old := freePort(t)
	r.openOn("main", "postgres", old, local, 0)
	c := establishedPG(t, old)
	next := freePort(t)
	if got, err := r.openOn("main", "postgres", next, local, 0); err != nil || got != next {
		t.Fatalf("%d %v", got, err)
	}
	if _, err := c.Read(make([]byte, 1)); err == nil {
		t.Error("a connection on the old port survived")
	}
	if cn, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(old), time.Second); err == nil {
		cn.Close()
		t.Error("the old port is still open")
	}
	establishedPG(t, next)
	if l := r.m.List(); len(l) != 1 || l[0].Port != next {
		t.Errorf("list = %+v", l)
	}
}

func TestPortInUseIsReported(t *testing.T) {
	r := newRig(t, "postgres", nil)
	// Taken by something else on the machine.
	busy, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer busy.Close()
	port := busy.Addr().(*net.TCPAddr).Port
	if _, err := r.openOn("main", "postgres", port, local, 0); !errors.Is(err, ErrPortInUse) {
		t.Errorf("err = %v, want ErrPortInUse", err)
	}
	if len(r.m.List()) != 0 {
		t.Error("something was left open")
	}
	// Taken by another server of ours.
	free := freePort(t)
	if _, err := r.openOn("one", "postgres", free, local, 0); err != nil {
		t.Fatal(err)
	}
	if _, err := r.openOn("two", "postgres", free, local, 0); !errors.Is(err, ErrPortInUse) {
		t.Errorf("err = %v, want ErrPortInUse", err)
	}
}

func TestAtMostEightListeners(t *testing.T) {
	r := newRig(t, "postgres", nil)
	for i := 0; i < 8; i++ {
		if _, err := r.open("srv-"+strconv.Itoa(i), "postgres", local, 0); err != nil {
			t.Fatalf("listener %d: %v", i, err)
		}
	}
	var in *validate.InputError
	if _, err := r.open("srv-8", "postgres", local, 0); !errors.As(err, &in) {
		t.Errorf("a ninth listener: %v", err)
	}
	// Replacing one of the eight is fine.
	if _, err := r.open("srv-3", "postgres", local, 0); err != nil {
		t.Errorf("replacing: %v", err)
	}
	if n := len(r.m.List()); n != 8 {
		t.Errorf("%d listeners", n)
	}
}

func TestAtMostFiftyConnectionsPerListener(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, _ := r.open("main", "postgres", local, 0)
	var keep []net.Conn
	for i := 0; i < 50; i++ {
		keep = append(keep, establishedPG(t, port))
	}
	closedWithoutBytes(t, dial(t, port), "51st connection")
	if l := r.m.List(); l[0].Connections != 50 {
		t.Errorf("connections = %d", l[0].Connections)
	}
	keep[0].Close()
	eventually(t, "a place to free up", func() bool { return r.m.List()[0].Connections == 49 })
	establishedPG(t, port)
	if l := r.m.List(); l[0].Total < 51 {
		t.Errorf("total = %d", l[0].Total)
	}
}

func TestCloseKillsConnectionsAndIsIdempotent(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, _ := r.open("main", "postgres", local, 0)
	c := establishedPG(t, port)
	if err := r.m.Close("main"); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Read(make([]byte, 1)); err == nil {
		t.Error("connection survived Close")
	}
	if err := r.m.Close("main"); err != nil {
		t.Errorf("second close: %v", err)
	}
	if err := r.m.Close("never-opened"); err != nil {
		t.Errorf("closing an unknown server: %v", err)
	}
	if err := r.m.Close("../x"); err == nil {
		t.Error("a bad slug was accepted")
	}
	if len(r.m.List()) != 0 {
		t.Error("still listed")
	}
}

func TestShutdownClosesEverythingAndLeavesNoGoroutines(t *testing.T) {
	r := newRig(t, "postgres", nil)
	var conns []net.Conn
	for i := 0; i < 3; i++ {
		port, _ := r.open("srv-"+strconv.Itoa(i), "postgres", local, 0)
		conns = append(conns, establishedPG(t, port))
	}
	done := make(chan struct{})
	go func() { r.m.Shutdown(); close(done) }()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Shutdown did not return")
	}
	for _, c := range conns {
		if _, err := c.Read(make([]byte, 1)); err == nil {
			t.Error("a connection survived Shutdown")
		}
	}
	if len(r.m.List()) != 0 {
		t.Error("listeners remain")
	}
}

func TestListReportsConnectionsAndTotals(t *testing.T) {
	r := newRig(t, "postgres", nil)
	port, _ := r.open("main", "postgres", []string{"127.0.0.1", "203.0.113.0/24"}, 0)
	c1 := establishedPG(t, port)
	establishedPG(t, port)
	c1.Close()
	eventually(t, "one connection left", func() bool { l := r.m.List()[0]; return l.Connections == 1 && l.Total == 2 })
	l := r.m.List()[0]
	if l.Server != "main" || l.Port != port || l.ExpiresAt != 0 || len(l.Allow) != 2 || l.Allow[0] != "127.0.0.1/32" {
		t.Errorf("list = %+v", l)
	}
}

// upper is ASCII-only, so arbitrary bytes pass through unchanged apart from letters.
func upper(b []byte) []byte {
	out := make([]byte, len(b))
	for i, c := range b {
		if c >= 'a' && c <= 'z' {
			c -= 32
		}
		out[i] = c
	}
	return out
}

func (f *fakeDocker) setAddrErr(err error) { f.mu.Lock(); f.addrErr = err; f.mu.Unlock() }
