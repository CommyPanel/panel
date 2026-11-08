// Package dbremote lets a database server be reached from the internet: the
// agent opens a public TCP port itself and forwards to the server's container.
// It is security-critical, so it is deliberately narrow:
//
//   - only sources on the allow list get a byte; the others are closed at accept
//     without a banner,
//   - a listener lives only in memory, ends at its expiry (active connections
//     included) and never survives an agent restart (it fails closed),
//   - the client must ask for TLS before anything else is relayed (see
//     protocol.go); the proxy does not terminate TLS, the database does,
//   - the backend is found fresh on every connection from Docker, never from a
//     request, and is always the engine's own port in the server's own container.
package dbremote

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"commypanel/agent/internal/validate"
)

// ErrPortInUse means the port is taken (the panel retries with another one).
var ErrPortInUse = errors.New("port in use")

const (
	defaultMaxListeners = 8
	defaultMaxConns     = 50
	maxAllow            = 20
	maxExpiry           = 400 * 24 * time.Hour
	handshakeTimeout    = 10 * time.Second
	dialTimeout         = 5 * time.Second
	refusedLogEvery     = time.Minute
)

// neverPorts are ports a database must not be offered on, whatever the range.
var neverPorts = []int{22, 80, 443, 2053, 8443}

// Docker is what the proxy needs from Docker.
type Docker interface {
	// CheckDatabaseServer says whether db-<server> is a managed database server of this engine.
	CheckDatabaseServer(ctx context.Context, server, engine string) error
	// DatabaseAddress is the container's current IP on its own network.
	DatabaseAddress(ctx context.Context, server string) (string, error)
}

// PortRange is the range of ports a listener may use.
type PortRange struct{ Min, Max int }

// ParsePortRange parses "10000-19999".
func ParsePortRange(s string) (PortRange, error) {
	lo, hi, ok := strings.Cut(s, "-")
	min, err1 := strconv.Atoi(strings.TrimSpace(lo))
	max, err2 := strconv.Atoi(strings.TrimSpace(hi))
	if !ok || err1 != nil || err2 != nil || min < 1024 || max > 65535 || min > max {
		return PortRange{}, fmt.Errorf("invalid port range %q (want MIN-MAX between 1024 and 65535)", s)
	}
	return PortRange{min, max}, nil
}

// Config sets the limits; the zero value of everything but Ports is usable.
type Config struct {
	Ports    PortRange
	Reserved []int // further ports never offered (the agent's and the panel's own)
	// Overridable for tests.
	Now          func() time.Time
	AfterFunc    func(d time.Duration, f func()) (stop func() bool)
	Listen       func(port int) (net.Listener, error)
	Dial         func(ctx context.Context, addr string) (net.Conn, error)
	MaxListeners int
	MaxConns     int
	Handshake    time.Duration

	allowLoopback bool // tests connect from 127.0.0.1
}

// Manager owns the listeners.
type Manager struct {
	cfg    Config
	docker Docker

	mu       sync.Mutex
	byServer map[string]*listener
}

// New makes a manager.
func New(cfg Config, d Docker) *Manager {
	if cfg.Ports == (PortRange{}) {
		cfg.Ports = PortRange{10000, 19999}
	}
	if cfg.Now == nil {
		cfg.Now = time.Now
	}
	if cfg.AfterFunc == nil {
		cfg.AfterFunc = func(d time.Duration, f func()) func() bool { return time.AfterFunc(d, f).Stop }
	}
	if cfg.Listen == nil {
		cfg.Listen = func(port int) (net.Listener, error) { return net.Listen("tcp", ":"+strconv.Itoa(port)) }
	}
	if cfg.Dial == nil {
		cfg.Dial = func(ctx context.Context, addr string) (net.Conn, error) {
			return (&net.Dialer{Timeout: dialTimeout}).DialContext(ctx, "tcp", addr)
		}
	}
	if cfg.MaxListeners == 0 {
		cfg.MaxListeners = defaultMaxListeners
	}
	if cfg.MaxConns == 0 {
		cfg.MaxConns = defaultMaxConns
	}
	if cfg.Handshake == 0 {
		cfg.Handshake = handshakeTimeout
	}
	return &Manager{cfg: cfg, docker: d, byServer: map[string]*listener{}}
}

// OpenRequest is the panel's request to open (or update) a server's port.
type OpenRequest struct {
	Server    string   `json:"server"`
	Engine    string   `json:"engine"`
	Port      int      `json:"port"`
	Allow     []string `json:"allow"`
	ExpiresAt int64    `json:"expires_at"`
}

// Info describes an open listener.
type Info struct {
	Server      string   `json:"server"`
	Port        int      `json:"port"`
	Allow       []string `json:"allow"`
	ExpiresAt   int64    `json:"expires_at"`
	Connections int      `json:"connections"`
	Total       int      `json:"total"`
}

func input(format string, args ...any) error {
	return &validate.InputError{Msg: fmt.Sprintf(format, args...)}
}

// CheckPort checks a port against the range and the ports never offered.
func (m *Manager) CheckPort(port int) error {
	if port < m.cfg.Ports.Min || port > m.cfg.Ports.Max {
		return input("port must be between %d and %d", m.cfg.Ports.Min, m.cfg.Ports.Max)
	}
	for _, p := range neverPorts {
		if port == p {
			return input("port %d cannot be used", port)
		}
	}
	for _, p := range m.cfg.Reserved {
		if port == p {
			return input("port %d cannot be used", port)
		}
	}
	return nil
}

// ParseAllow parses the allow list: prefixes (a bare address is a single host),
// no prefix wider than /16 (IPv4) or /48 (IPv6), nothing local or multicast.
func ParseAllow(entries []string) ([]netip.Prefix, error) { return parseAllow(entries, false) }

func parseAllow(entries []string, loopback bool) ([]netip.Prefix, error) {
	if len(entries) == 0 || len(entries) > maxAllow {
		return nil, input("allow needs between 1 and %d entries", maxAllow)
	}
	out := make([]netip.Prefix, 0, len(entries))
	for _, e := range entries {
		var p netip.Prefix
		if a, err := netip.ParseAddr(e); err == nil {
			p = netip.PrefixFrom(a, a.BitLen())
		} else if p, err = netip.ParsePrefix(e); err != nil {
			return nil, input("invalid address %q", e)
		}
		a := p.Addr()
		if !p.IsValid() || a.Zone() != "" || a.Is4In6() {
			return nil, input("invalid address %q (write IPv4 addresses in IPv4 form)", e)
		}
		if a.IsUnspecified() || (a.IsLoopback() && !loopback) || a.IsMulticast() || a.IsLinkLocalUnicast() || a.IsLinkLocalMulticast() {
			return nil, input("address %q cannot be allowed", e)
		}
		if (a.Is4() && p.Bits() < 16) || (a.Is6() && p.Bits() < 48) {
			return nil, input("%q is too wide: use at least /16 (IPv4) or /48 (IPv6)", e)
		}
		out = append(out, p)
	}
	return out, nil
}

func (m *Manager) parseExpiry(at int64) (time.Time, error) {
	if at == 0 {
		return time.Time{}, nil
	}
	t := time.Unix(at, 0)
	now := m.cfg.Now()
	if !t.After(now) || t.After(now.Add(maxExpiry)) {
		return time.Time{}, input("expires_at must be in the future and less than 400 days away")
	}
	return t, nil
}

// Open opens the public port of a server, or replaces what is open for it.
func (m *Manager) Open(ctx context.Context, req OpenRequest) (int, error) {
	if err := validate.DatabaseServer(req.Server); err != nil {
		return 0, err
	}
	if err := validate.DatabaseEngine(req.Engine); err != nil {
		return 0, err
	}
	if err := m.CheckPort(req.Port); err != nil {
		return 0, err
	}
	allow, err := parseAllow(req.Allow, m.cfg.allowLoopback)
	if err != nil {
		return 0, err
	}
	expires, err := m.parseExpiry(req.ExpiresAt)
	if err != nil {
		return 0, err
	}
	if err := m.docker.CheckDatabaseServer(ctx, req.Server, req.Engine); err != nil {
		return 0, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	old := m.byServer[req.Server]
	if old != nil && old.port == req.Port {
		old.update(req.Engine, allow, expires)
		return req.Port, nil
	}
	for _, other := range m.byServer {
		if other.port == req.Port {
			return 0, ErrPortInUse
		}
	}
	count := len(m.byServer)
	if old != nil {
		count--
	}
	if count >= m.cfg.MaxListeners {
		return 0, input("at most %d servers can be open at once", m.cfg.MaxListeners)
	}
	if old != nil {
		old.stop()
		delete(m.byServer, req.Server)
	}
	ln, err := m.cfg.Listen(req.Port)
	if err != nil {
		if errors.Is(err, syscall.EADDRINUSE) {
			return 0, ErrPortInUse
		}
		return 0, fmt.Errorf("listen on port %d: %w", req.Port, err)
	}
	l := &listener{m: m, server: req.Server, engine: req.Engine, port: req.Port, ln: ln,
		conns: map[*session]struct{}{}, lastRefused: map[netip.Addr]time.Time{}}
	l.allow, l.expires = allow, expires
	l.schedule()
	m.byServer[req.Server] = l
	l.wg.Add(1)
	go l.acceptLoop()
	slog.Info("database remote access opened", "server", req.Server, "port", req.Port, "allow", len(allow), "expires", expires)
	return req.Port, nil
}

// Close closes a server's listener and every connection through it.
func (m *Manager) Close(server string) error {
	if err := validate.DatabaseServer(server); err != nil {
		return err
	}
	m.mu.Lock()
	l := m.byServer[server]
	delete(m.byServer, server)
	m.mu.Unlock()
	if l != nil {
		l.stop()
		slog.Info("database remote access closed", "server", server)
	}
	return nil
}

// List describes every open listener.
func (m *Manager) List() []Info {
	m.mu.Lock()
	ls := make([]*listener, 0, len(m.byServer))
	for _, l := range m.byServer {
		ls = append(ls, l)
	}
	m.mu.Unlock()
	out := make([]Info, 0, len(ls))
	for _, l := range ls {
		out = append(out, l.info())
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Server < out[j].Server })
	return out
}

// Shutdown closes everything and waits for the goroutines to end.
func (m *Manager) Shutdown() {
	m.mu.Lock()
	ls := make([]*listener, 0, len(m.byServer))
	for _, l := range m.byServer {
		ls = append(ls, l)
	}
	m.byServer = map[string]*listener{}
	m.mu.Unlock()
	for _, l := range ls {
		l.stop()
		l.wg.Wait()
	}
}

// expire ends a listener whose time has come (called by its timer, and by an
// accept that sees the time has passed).
func (m *Manager) expire(l *listener) {
	if !l.expired() {
		return
	}
	m.mu.Lock()
	if m.byServer[l.server] == l {
		delete(m.byServer, l.server)
	}
	m.mu.Unlock()
	l.stop()
	slog.Info("database remote access expired", "server", l.server, "port", l.port)
}

type listener struct {
	m      *Manager
	server string
	port   int
	ln     net.Listener
	wg     sync.WaitGroup

	mu          sync.Mutex
	engine      string
	allow       []netip.Prefix
	expires     time.Time // zero = never
	stopTimer   func() bool
	conns       map[*session]struct{}
	total       int
	refused     int
	closed      bool
	lastRefused map[netip.Addr]time.Time
}

func (l *listener) info() Info {
	l.mu.Lock()
	defer l.mu.Unlock()
	in := Info{Server: l.server, Port: l.port, Connections: len(l.conns), Total: l.total, Allow: make([]string, 0, len(l.allow))}
	for _, p := range l.allow {
		in.Allow = append(in.Allow, p.String())
	}
	if !l.expires.IsZero() {
		in.ExpiresAt = l.expires.Unix()
	}
	return in
}

func (l *listener) expired() bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.expiredLocked()
}

func (l *listener) expiredLocked() bool {
	return !l.expires.IsZero() && !l.m.cfg.Now().Before(l.expires)
}

func (l *listener) allowedLocked(a netip.Addr) bool {
	for _, p := range l.allow {
		if p.Contains(a) {
			return true
		}
	}
	return false
}

// schedule (re)arms the expiry timer; the caller holds no listener lock.
func (l *listener) schedule() {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.stopTimer != nil {
		l.stopTimer()
		l.stopTimer = nil
	}
	if l.expires.IsZero() || l.closed {
		return
	}
	d := l.expires.Sub(l.m.cfg.Now())
	if d < 0 {
		d = 0
	}
	l.stopTimer = l.m.cfg.AfterFunc(d, func() { l.m.expire(l) })
}

// update replaces the allow list and expiry; connections whose source is no
// longer allowed are closed, the others are kept.
func (l *listener) update(engine string, allow []netip.Prefix, expires time.Time) {
	l.mu.Lock()
	l.engine, l.allow, l.expires = engine, allow, expires
	var drop []*session
	for s := range l.conns {
		if !l.allowedLocked(s.src) {
			drop = append(drop, s)
		}
	}
	l.mu.Unlock()
	for _, s := range drop {
		s.close()
	}
	l.schedule()
}

// stop closes the listener and every connection. It does not wait.
func (l *listener) stop() {
	l.mu.Lock()
	l.closed = true
	if l.stopTimer != nil {
		l.stopTimer()
		l.stopTimer = nil
	}
	all := make([]*session, 0, len(l.conns))
	for s := range l.conns {
		all = append(all, s)
	}
	l.mu.Unlock()
	_ = l.ln.Close()
	for _, s := range all {
		s.close()
	}
}

func (l *listener) isClosed() bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.closed
}

func (l *listener) acceptLoop() {
	defer l.wg.Done()
	for {
		c, err := l.ln.Accept()
		if err != nil {
			if l.isClosed() || errors.Is(err, net.ErrClosed) {
				return
			}
			time.Sleep(50 * time.Millisecond) // out of descriptors, say; try again
			continue
		}
		l.wg.Add(1)
		go func() {
			defer l.wg.Done()
			l.handle(c)
		}()
	}
}

// sourceOf is the address a connection comes from, IPv4-in-IPv6 unmapped.
func sourceOf(c net.Conn) (netip.Addr, bool) {
	ap, err := netip.ParseAddrPort(c.RemoteAddr().String())
	if err != nil {
		return netip.Addr{}, false
	}
	return ap.Addr().Unmap().WithZone(""), true
}

func (l *listener) handle(c net.Conn) {
	defer c.Close()
	src, ok := sourceOf(c)
	l.mu.Lock()
	var s *session
	var expired bool
	switch {
	case l.closed:
	case !ok || !l.allowedLocked(src):
		l.refusedLocked(src, ok)
	case l.expiredLocked():
		expired = true
	case len(l.conns) >= l.m.cfg.MaxConns:
	default:
		s = &session{client: c, src: src}
		l.conns[s] = struct{}{}
		l.total++
	}
	engine := l.engine
	l.mu.Unlock()
	if expired {
		l.m.expire(l)
	}
	if s == nil {
		return // closed without a byte
	}
	defer func() {
		s.close()
		l.mu.Lock()
		delete(l.conns, s)
		l.mu.Unlock()
	}()
	l.serve(s, engine)
}

func (l *listener) refusedLocked(src netip.Addr, ok bool) {
	l.refused++
	if !ok {
		return
	}
	now := l.m.cfg.Now()
	if last, seen := l.lastRefused[src]; seen && now.Sub(last) < refusedLogEvery {
		return
	}
	if len(l.lastRefused) > 1024 {
		l.lastRefused = map[netip.Addr]time.Time{}
	}
	l.lastRefused[src] = now
	slog.Info("database remote access refused a source", "server", l.server, "port", l.port, "source", src.String())
}

func (l *listener) serve(s *session, engine string) {
	deadline := time.Now().Add(l.m.cfg.Handshake)
	_ = s.client.SetDeadline(deadline)
	ctx, cancel := context.WithTimeout(context.Background(), dialTimeout)
	ip, err := l.m.docker.DatabaseAddress(ctx, l.server)
	if err != nil {
		cancel()
		return
	}
	port := "3306"
	if engine == "postgres" {
		port = "5432"
	}
	backend, err := l.m.cfg.Dial(ctx, net.JoinHostPort(ip, port))
	cancel()
	if err != nil {
		return
	}
	if !s.setBackend(backend) {
		return
	}
	_ = backend.SetDeadline(deadline)

	if err := firstStep(engine, s.client, backend); err != nil {
		return
	}
	_ = s.client.SetDeadline(time.Time{})
	_ = backend.SetDeadline(time.Time{})
	keepAlive(s.client)
	keepAlive(backend)
	s.pipe()
}

// firstStep runs the protocol check that the client uses TLS.
func firstStep(engine string, client, backend net.Conn) error {
	if engine == "postgres" {
		fwd, cancel, err := postgresFirstStep(client)
		if err != nil {
			return err
		}
		if _, err := backend.Write(fwd); err != nil {
			return err
		}
		if cancel {
			return nil
		}
		// The server must agree to TLS ('S'); an 'N' would let a client that tolerates it go on in the clear.
		var answer [1]byte
		if _, err := io.ReadFull(backend, answer[:]); err != nil {
			return err
		}
		if answer[0] != 'S' {
			return errNotTLS
		}
		_, err = client.Write(answer[:])
		return err
	}
	if err := mysqlRelayHandshake(backend, client); err != nil {
		return err
	}
	pkt, err := mysqlSSLRequestPacket(client)
	if err != nil {
		return err
	}
	_, err = backend.Write(pkt)
	return err
}

func keepAlive(c net.Conn) {
	if tc, ok := c.(*net.TCPConn); ok {
		_ = tc.SetKeepAlive(true)
		_ = tc.SetKeepAlivePeriod(30 * time.Second)
	}
}

// session is one client connection and its connection to the database.
type session struct {
	client net.Conn
	src    netip.Addr

	mu      sync.Mutex
	backend net.Conn
	closed  bool
}

func (s *session) setBackend(b net.Conn) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.closed {
		_ = b.Close()
		return false
	}
	s.backend = b
	return true
}

func (s *session) close() {
	s.mu.Lock()
	s.closed = true
	b := s.backend
	s.mu.Unlock()
	_ = s.client.Close()
	if b != nil {
		_ = b.Close()
	}
}

// pipe copies both ways until both are done. When one side ends cleanly the other
// is half-closed; any error closes both.
func (s *session) pipe() {
	s.mu.Lock()
	b := s.backend
	s.mu.Unlock()
	var wg sync.WaitGroup
	wg.Add(2)
	cp := func(dst, src net.Conn) {
		defer wg.Done()
		if _, err := io.Copy(dst, src); err != nil {
			s.close()
			return
		}
		if cw, ok := dst.(interface{ CloseWrite() error }); ok {
			_ = cw.CloseWrite()
		} else {
			s.close()
		}
	}
	go cp(b, s.client)
	go cp(s.client, b)
	wg.Wait()
}
