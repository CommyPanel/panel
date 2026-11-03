// Package dbtool lets a person open a database in phpMyAdmin (MySQL, MariaDB) or
// Adminer (PostgreSQL) without installing anything.
//
// A session starts a small container of the tool on the database server's own
// network, logged in as that one database's own user, and removes it again after
// fifteen minutes without use. The browser reaches it through a proxy that lives
// here, on its own Unix socket and its own web address (another port than the
// panel, so another origin: the tool is a third-party web application, and
// whatever it ever shows must not be able to act as the panel).
//
// Everything is in memory. If the agent restarts, sessions are gone and the
// containers they left are removed at start-up.
package dbtool

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"

	"commypanel/agent/internal/docker"
	"commypanel/agent/internal/validate"
)

// Stages a session goes through, as the panel shows them.
const (
	StagePulling  = "pulling"  // downloading the tool's image (the first time only)
	StageStarting = "starting" // creating its container
	StageWaiting  = "waiting"  // waiting for it to answer
	StageReady    = "ready"
	StageFailed   = "failed"
)

// Runner is the part of the Docker manager the sessions use.
type Runner interface {
	PullToolImage(ctx context.Context, image string) error
	RunTool(ctx context.Context, t docker.ToolSpec) error
	Stop(ctx context.Context, name string) error
	Remove(ctx context.Context, name string) error
	ToolContainers(ctx context.Context) ([]string, error)
}

// Config holds the limits. The zero value is replaced by sensible ones.
type Config struct {
	IdleTimeout  time.Duration // a session unused this long is removed
	MaxAge       time.Duration // and none lives longer than this, used or not
	MaxSessions  int
	StartTimeout time.Duration // pulling, starting and waiting together
	ReadyTimeout time.Duration // waiting for the tool to answer
	TicketTTL    time.Duration // how long a ticket waits for the browser
	MaxUpload    int64         // largest request body, for importing a dump
	PortMin      int           // the loopback ports tool containers are published on
	PortMax      int
	// AllocatePort replaces the search of the port range (tests).
	AllocatePort func() (int, error)
	// Upstream is the address the published ports are on.
	Upstream string
	Now      func() time.Time
}

func (c Config) withDefaults() Config {
	if c.IdleTimeout == 0 {
		c.IdleTimeout = 15 * time.Minute
	}
	if c.MaxAge == 0 {
		c.MaxAge = 4 * time.Hour
	}
	if c.MaxSessions == 0 {
		c.MaxSessions = 6
	}
	if c.StartTimeout == 0 {
		c.StartTimeout = 5 * time.Minute
	}
	if c.ReadyTimeout == 0 {
		c.ReadyTimeout = 90 * time.Second
	}
	if c.TicketTTL == 0 {
		c.TicketTTL = 60 * time.Second
	}
	if c.MaxUpload == 0 {
		c.MaxUpload = 130 << 20
	}
	if c.PortMin == 0 {
		c.PortMin, c.PortMax = 29000, 29099
	}
	if c.Upstream == "" {
		c.Upstream = "127.0.0.1"
	}
	if c.Now == nil {
		c.Now = time.Now
	}
	return c
}

// StartRequest says which database to open and where the browser will see it.
type StartRequest struct {
	Engine   string `json:"engine"`
	Server   string `json:"server"`   // the database server's slug
	Database string `json:"database"` // the database to open
	User     string `json:"user"`     // that database's own user
	Password string `json:"password"`
	// PublicBase is the web address of the tools port as the browser reaches it,
	// such as https://commy.example.com:2053 .
	PublicBase string `json:"public_base"`
}

// View is what the panel may know about a session. It never holds a secret.
type View struct {
	ID       string `json:"id"`
	Stage    string `json:"stage"`
	Error    string `json:"error,omitempty"`
	Tool     string `json:"tool"`
	Server   string `json:"server"`
	Database string `json:"database"`
}

type session struct {
	View
	engine   string
	user     string
	password string
	baseURL  string
	prefix   string // /s/<id>
	name     string // the container
	port     int

	created  time.Time
	lastSeen time.Time
	cookies  [][32]byte // hashes of the cookies handed out, newest last
}

type ticket struct {
	session string
	expires time.Time
}

// Manager owns the sessions and serves the proxy.
type Manager struct {
	cfg    Config
	runner Runner

	mu       sync.Mutex
	sessions map[string]*session
	tickets  map[[32]byte]ticket
	ctx      context.Context
}

// New returns a manager. ctx ends the sessions it is still starting.
func New(ctx context.Context, runner Runner, cfg Config) *Manager {
	return &Manager{cfg: cfg.withDefaults(), runner: runner, sessions: map[string]*session{}, tickets: map[[32]byte]ticket{}, ctx: ctx}
}

var publicBaseRe = regexp.MustCompile(`^https://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)(:[0-9]{1,5})?$`)

func randomToken(n int) string {
	var raw = make([]byte, n)
	if _, err := rand.Read(raw); err != nil {
		panic(err) // the system's random source failing is not something to carry on through
	}
	return base64.RawURLEncoding.EncodeToString(raw)
}

func hash(s string) [32]byte { return sha256.Sum256([]byte(s)) }

// Start begins a session and returns at once; the stages follow in Status.
// A database has one session at a time: starting another replaces the first.
func (m *Manager) Start(req StartRequest) (View, error) {
	tool, _, _, err := docker.ToolFor(req.Engine)
	if err != nil {
		return View{}, err
	}
	if err := validate.Name(req.Server); err != nil {
		return View{}, err
	}
	if err := validate.DatabaseIdent(req.Database); err != nil {
		return View{}, err
	}
	if err := validate.DatabaseIdent(req.User); err != nil {
		return View{}, err
	}
	if err := validate.DatabasePassword(req.Password); err != nil {
		return View{}, err
	}
	if !publicBaseRe.MatchString(req.PublicBase) {
		return View{}, &validate.InputError{Msg: "invalid tool address"}
	}

	m.mu.Lock()
	var replaced []*session
	for id, s := range m.sessions {
		if s.Server == req.Server && s.Database == req.Database {
			replaced = append(replaced, s)
			delete(m.sessions, id)
		}
	}
	if len(m.sessions) >= m.cfg.MaxSessions {
		m.mu.Unlock()
		m.removeAll(replaced)
		return View{}, &validate.InputError{Msg: "too many database tools are open; close one or wait until one closes by itself"}
	}
	port, err := m.allocatePortLocked()
	if err != nil {
		m.mu.Unlock()
		m.removeAll(replaced)
		return View{}, err
	}
	id := randomToken(24)
	now := m.cfg.Now()
	s := &session{
		View:     View{ID: id, Stage: StagePulling, Tool: tool, Server: req.Server, Database: req.Database},
		engine:   req.Engine,
		user:     req.User,
		password: req.Password,
		baseURL:  req.PublicBase + "/s/" + id + "/",
		prefix:   "/s/" + id,
		name:     "dbtool-" + hex.EncodeToString([]byte(randomToken(9))[:6]),
		port:     port,
		created:  now,
		lastSeen: now,
	}
	m.sessions[id] = s
	view := s.View
	m.mu.Unlock()

	m.removeAll(replaced)
	go m.run(s)
	return view, nil
}

func (m *Manager) allocatePortLocked() (int, error) {
	if m.cfg.AllocatePort != nil {
		return m.cfg.AllocatePort()
	}
	used := map[int]bool{}
	for _, s := range m.sessions {
		used[s.port] = true
	}
	for port := m.cfg.PortMin; port <= m.cfg.PortMax; port++ {
		if used[port] {
			continue
		}
		ln, err := net.Listen("tcp", fmt.Sprintf("%s:%d", m.cfg.Upstream, port))
		if err != nil {
			continue
		}
		ln.Close()
		return port, nil
	}
	return 0, &validate.InputError{Msg: "no free port for a database tool"}
}

// run does the slow part: download, start, wait.
func (m *Manager) run(s *session) {
	ctx, cancel := context.WithTimeout(m.ctx, m.cfg.StartTimeout)
	defer cancel()

	fail := func(err error) {
		slog.Warn("database tool did not start", "tool", s.Tool, "server", s.Server, "err", err)
		var input *validate.InputError
		msg := "The tool could not be started."
		if errors.As(err, &input) {
			msg = input.Msg
		}
		m.setStage(s, StageFailed, msg)
		cleanup, done := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		defer done()
		_ = m.runner.Stop(cleanup, s.name)
		_ = m.runner.Remove(cleanup, s.name)
	}

	_, image, _, _ := docker.ToolFor(s.engine)
	if err := m.runner.PullToolImage(ctx, image); err != nil {
		fail(err)
		return
	}
	if !m.setStage(s, StageStarting, "") {
		return
	}
	spec := docker.ToolSpec{Name: s.name, Tool: s.Tool, Engine: s.engine, Server: s.Server, User: s.user, Password: s.password, Port: s.port, BaseURL: s.baseURL}
	if err := m.runner.RunTool(ctx, spec); err != nil {
		fail(err)
		return
	}
	if !m.setStage(s, StageWaiting, "") {
		_ = m.runner.Stop(context.WithoutCancel(ctx), s.name)
		_ = m.runner.Remove(context.WithoutCancel(ctx), s.name)
		return
	}
	if err := m.waitReady(ctx, s); err != nil {
		fail(&validate.InputError{Msg: "The tool started but did not answer in time."})
		return
	}
	if !m.setStage(s, StageReady, "") {
		_ = m.runner.Stop(context.WithoutCancel(ctx), s.name)
		_ = m.runner.Remove(context.WithoutCancel(ctx), s.name)
		return
	}
	m.touch(s)
	slog.Info("database tool ready", "tool", s.Tool, "server", s.Server, "database", s.Database)
}

// setStage records progress. It returns false when the session was closed
// meanwhile, so the work stops.
func (m *Manager) setStage(s *session, stage, msg string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.sessions[s.ID] != s {
		return false
	}
	s.Stage, s.Error = stage, msg
	return true
}

func (m *Manager) touch(s *session) {
	m.mu.Lock()
	s.lastSeen = m.cfg.Now()
	m.mu.Unlock()
}

// Status reports a session, or false when there is none (it ended, or the agent restarted).
func (m *Manager) Status(id string) (View, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.sessions[id]
	if !ok {
		return View{}, false
	}
	return s.View, true
}

// ErrNotReady means the session is not running yet (or any more).
var ErrNotReady = &validate.InputError{Msg: "the database tool is not ready"}

// Ticket hands out the one-time secret that lets a browser into a ready session.
func (m *Manager) Ticket(id string) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.sessions[id]
	if !ok || s.Stage != StageReady {
		return "", ErrNotReady
	}
	now := m.cfg.Now()
	for k, t := range m.tickets {
		if now.After(t.expires) {
			delete(m.tickets, k)
		}
	}
	if len(m.tickets) >= 32 {
		return "", &validate.InputError{Msg: "too many tickets are waiting"}
	}
	token := randomToken(32)
	m.tickets[hash(token)] = ticket{session: id, expires: now.Add(m.cfg.TicketTTL)}
	return token, nil
}

// redeem consumes a ticket. A second use, an expired one and a made-up one all fail the same way.
func (m *Manager) redeem(token string) (*session, bool) {
	if len(token) != 43 {
		return nil, false
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	key := hash(token)
	t, ok := m.tickets[key]
	if !ok {
		return nil, false
	}
	delete(m.tickets, key)
	if m.cfg.Now().After(t.expires) {
		return nil, false
	}
	s, ok := m.sessions[t.session]
	if !ok || s.Stage != StageReady {
		return nil, false
	}
	return s, true
}

// grantCookie makes a new browser secret for a session and remembers its hash.
func (m *Manager) grantCookie(s *session) string {
	value := randomToken(32)
	m.mu.Lock()
	defer m.mu.Unlock()
	s.cookies = append(s.cookies, hash(value))
	if len(s.cookies) > 8 {
		s.cookies = s.cookies[len(s.cookies)-8:]
	}
	return value
}

// authorized reports whether a cookie value belongs to the session.
func (m *Manager) authorized(s *session, value string) bool {
	if value == "" || len(value) > 64 {
		return false
	}
	h := hash(value)
	m.mu.Lock()
	defer m.mu.Unlock()
	ok := false
	for _, c := range s.cookies {
		if subtle.ConstantTimeCompare(c[:], h[:]) == 1 {
			ok = true
		}
	}
	return ok
}

// Stop ends a session and removes its container. Stopping one that is gone is fine.
func (m *Manager) Stop(id string) {
	m.mu.Lock()
	s, ok := m.sessions[id]
	delete(m.sessions, id)
	m.mu.Unlock()
	if ok {
		m.removeAll([]*session{s})
	}
}

func (m *Manager) removeAll(list []*session) {
	for _, s := range list {
		go func(s *session) {
			ctx, cancel := context.WithTimeout(context.WithoutCancel(m.ctx), 30*time.Second)
			defer cancel()
			if err := m.runner.Stop(ctx, s.name); err != nil {
				slog.Warn("could not stop a database tool", "container", s.name, "err", err)
			}
			if err := m.runner.Remove(ctx, s.name); err != nil {
				slog.Warn("could not remove a database tool", "container", s.name, "err", err)
			}
			slog.Info("database tool closed", "tool", s.Tool, "server", s.Server, "database", s.Database)
		}(s)
	}
}

// Reap closes the sessions that have been idle for too long or have lived too long.
func (m *Manager) Reap() {
	now := m.cfg.Now()
	var old []*session

	m.mu.Lock()
	for id, s := range m.sessions {
		idle := s.Stage == StageReady && now.Sub(s.lastSeen) > m.cfg.IdleTimeout
		aged := now.Sub(s.created) > m.cfg.MaxAge
		failed := s.Stage == StageFailed && now.Sub(s.created) > 10*time.Minute
		if idle || aged || failed {
			delete(m.sessions, id)
			old = append(old, s)
		}
	}
	for k, t := range m.tickets {
		if now.After(t.expires) {
			delete(m.tickets, k)
		}
	}
	m.mu.Unlock()

	m.removeAll(old)
}

// RunReaper calls Reap every half minute until ctx ends.
func (m *Manager) RunReaper(ctx context.Context) {
	t := time.NewTicker(30 * time.Second)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			m.Reap()
		}
	}
}

// Shutdown closes every session and removes its container, and waits for that. It runs when
// the agent stops, so that a tool never outlives the agent that watches it.
func (m *Manager) Shutdown() {
	m.mu.Lock()
	var all []*session
	for id, s := range m.sessions {
		all = append(all, s)
		delete(m.sessions, id)
	}
	m.mu.Unlock()

	var wg sync.WaitGroup
	for _, s := range all {
		wg.Add(1)
		go func(s *session) {
			defer wg.Done()
			ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
			defer cancel()
			_ = m.runner.Stop(ctx, s.name)
			_ = m.runner.Remove(ctx, s.name)
		}(s)
	}
	wg.Wait()

	// And anything a session was still starting when it was closed.
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	m.Cleanup(ctx)
}

// Cleanup removes the tool containers a crash or a restart left behind. Sessions
// are in memory only, so none of them can still be in use.
func (m *Manager) Cleanup(ctx context.Context) {
	names, err := m.runner.ToolContainers(ctx)
	if err != nil {
		slog.Warn("could not list database tools", "err", err)
		return
	}
	for _, name := range names {
		_ = m.runner.Stop(ctx, name)
		_ = m.runner.Remove(ctx, name)
		slog.Info("removed a database tool left behind", "container", name)
	}
}

// waitReady polls the tool until it answers.
func (m *Manager) waitReady(ctx context.Context, s *session) error {
	ctx, cancel := context.WithTimeout(ctx, m.cfg.ReadyTimeout)
	defer cancel()
	client := &http.Client{Timeout: 3 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	target := fmt.Sprintf("http://%s:%d/", m.cfg.Upstream, s.port)
	for {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
		if resp, err := client.Do(req); err == nil {
			resp.Body.Close()
			if resp.StatusCode < 500 {
				return nil
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
}

// has reports whether text starts with any of the prefixes.
func hasPrefix(name string, prefixes []string) bool {
	for _, p := range prefixes {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}
