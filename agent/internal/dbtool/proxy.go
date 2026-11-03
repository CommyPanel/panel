package dbtool

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httputil"
	"net/url"
	"regexp"
	"strings"
	"time"
)

// What each tool may keep in the browser. Everything else a tool sets is dropped,
// and so is everything the browser holds that is not one of these, which is what
// keeps the panel's own cookies from ever reaching a tool, and a tool from
// planting a cookie that the panel would read.
var cookiePrefixes = map[string][]string{
	"phpmyadmin": {"phpMyAdmin", "pma", "__Secure-phpMyAdmin", "__Secure-pma"},
	"adminer":    {"adminer_"},
}

const authCookie = "cpdbtool_"

// ServeHTTP is the whole web side: /open?t=<ticket> and /s/<session>/... , and
// nothing else. It listens on a Unix socket that only the web server can reach.
func (m *Manager) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	h := w.Header()
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Referrer-Policy", "no-referrer")
	h.Set("Cache-Control", "no-store")

	switch {
	case r.URL.Path == "/open":
		m.open(w, r)
	case strings.HasPrefix(r.URL.Path, "/s/"):
		m.proxy(w, r)
	default:
		notFound(w)
	}
}

func notFound(w http.ResponseWriter) {
	http.Error(w, "Not found.", http.StatusNotFound)
}

// open exchanges a ticket for a browser session: it sets the cookie that proves
// the browser belongs to the session and sends it on to the tool, already logged in.
func (m *Manager) open(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "Method not allowed.", http.StatusMethodNotAllowed)
		return
	}
	s, ok := m.redeem(r.URL.Query().Get("t"))
	if !ok {
		http.Error(w, "This link has expired or was already used. Open the database from CommyPanel again.", http.StatusNotFound)
		return
	}
	m.touch(s)

	// Both tools show a login form to whoever reaches them; the agent logs in from here, so the
	// password never passes through the browser and never sits in a container.
	ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
	defer cancel()
	carry, landing, err := m.login(ctx, s)
	if err != nil {
		slog.Warn("could not log in to the database tool", "tool", s.Tool, "server", s.Server, "err", err)
		http.Error(w, "The tool did not accept the login. Open the database from CommyPanel again.", http.StatusBadGateway)
		return
	}

	http.SetCookie(w, m.cookieFor(s, authCookie+s.ID, m.grantCookie(s)))
	for _, c := range carry {
		http.SetCookie(w, m.cookieFor(s, c.Name, c.Value))
	}
	http.Redirect(w, r, landing, http.StatusSeeOther)
}

// cookieFor makes a cookie that exists only below the session's own path, over
// https, away from scripts, and never sent along with a request from another site.
func (m *Manager) cookieFor(s *session, name, value string) *http.Cookie {
	return &http.Cookie{Name: name, Value: value, Path: s.prefix + "/", Secure: true, HttpOnly: true, SameSite: http.SameSiteStrictMode}
}

// toolBrowser is a minimal browser for the login: it keeps cookies by hand, because
// phpMyAdmin's are marked Secure and a cookie jar will not hold those over plain http.
type toolBrowser struct {
	client  *http.Client
	base    string
	cookies map[string]string
	order   []string
}

func newToolBrowser(base string) *toolBrowser {
	return &toolBrowser{
		client:  &http.Client{Timeout: 15 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }},
		base:    base,
		cookies: map[string]string{},
	}
}

func (b *toolBrowser) do(ctx context.Context, method, path string, form url.Values) (*http.Response, string, error) {
	var body io.Reader
	if form != nil {
		body = strings.NewReader(form.Encode())
	}
	req, err := http.NewRequestWithContext(ctx, method, b.base+path, body)
	if err != nil {
		return nil, "", err
	}
	if form != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}
	for _, name := range b.order {
		req.AddCookie(&http.Cookie{Name: name, Value: b.cookies[name]})
	}
	resp, err := b.client.Do(req)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()
	text, _ := io.ReadAll(io.LimitReader(resp.Body, 2<<20))
	for _, c := range resp.Cookies() {
		if _, known := b.cookies[c.Name]; !known {
			b.order = append(b.order, c.Name)
		}
		b.cookies[c.Name] = c.Value // the last one the tool sent wins
	}
	return resp, string(text), nil
}

var (
	pmaTokenRe   = regexp.MustCompile(`name="token" value="([0-9a-f]+)"`)
	pmaSessionRe = regexp.MustCompile(`name="set_session" value="([0-9a-f]+)"`)
)

// login logs in to the session's tool as the database's own user. It returns the
// cookies the tool made, which are what lets the browser in, and the path to send
// the person to.
func (m *Manager) login(ctx context.Context, s *session) ([]*http.Cookie, string, error) {
	b := newToolBrowser(fmt.Sprintf("http://%s:%d", m.cfg.Upstream, s.port))

	switch s.Tool {
	case "phpmyadmin":
		_, page, err := b.do(ctx, http.MethodGet, "/index.php", nil)
		if err != nil {
			return nil, "", err
		}
		token, session := pmaTokenRe.FindStringSubmatch(page), pmaSessionRe.FindStringSubmatch(page)
		if token == nil || session == nil {
			return nil, "", fmt.Errorf("phpMyAdmin showed no login form")
		}
		resp, _, err := b.do(ctx, http.MethodPost, "/index.php", url.Values{
			"set_session":  {session[1]},
			"pma_username": {s.user},
			"pma_password": {s.password},
			"server":       {"1"},
			"route":        {"/"},
			"lang":         {"en"},
			"token":        {token[1]},
		})
		if err != nil {
			return nil, "", err
		}
		// A login that worked is sent on (302); a refusal shows the form again.
		if resp.StatusCode != http.StatusFound {
			return nil, "", fmt.Errorf("phpMyAdmin did not accept the login (%d)", resp.StatusCode)
		}
		return b.owned(s), s.prefix + "/index.php?route=/database/structure&db=" + url.QueryEscape(s.Database), nil

	case "adminer":
		if _, _, err := b.do(ctx, http.MethodGet, "/", nil); err != nil {
			return nil, "", err
		}
		resp, _, err := b.do(ctx, http.MethodPost, "/", url.Values{
			"auth[driver]":   {"pgsql"},
			"auth[server]":   {"db-" + s.Server},
			"auth[username]": {s.user},
			"auth[password]": {s.password},
			"auth[db]":       {s.Database},
		})
		if err != nil {
			return nil, "", err
		}
		location := resp.Header.Get("Location")
		if resp.StatusCode != http.StatusFound || !strings.HasPrefix(location, "?") {
			return nil, "", fmt.Errorf("Adminer did not accept the login (%d)", resp.StatusCode)
		}
		return b.owned(s), s.prefix + "/" + location, nil
	}
	return nil, "", fmt.Errorf("unknown tool %q", s.Tool)
}

// owned are the cookies the tool made that belong to it, in the order it made them.
func (b *toolBrowser) owned(s *session) []*http.Cookie {
	var out []*http.Cookie
	for _, name := range b.order {
		if hasPrefix(name, cookiePrefixes[s.Tool]) {
			out = append(out, &http.Cookie{Name: name, Value: b.cookies[name]})
		}
	}
	return out
}

// proxy passes a request to the session's tool, after checking the browser
// belongs to it. Whatever is wrong, the answer is the same plain 404.
func (m *Manager) proxy(w http.ResponseWriter, r *http.Request) {
	rest := strings.TrimPrefix(r.URL.Path, "/s/")
	id, path, _ := strings.Cut(rest, "/")
	path = "/" + path

	m.mu.Lock()
	s := m.sessions[id]
	ready := s != nil && s.Stage == StageReady
	m.mu.Unlock()
	if !ready {
		notFound(w)
		return
	}
	c, err := r.Cookie(authCookie + id)
	if err != nil || !m.authorized(s, c.Value) {
		notFound(w)
		return
	}

	switch r.Method {
	case http.MethodGet, http.MethodHead, http.MethodPost:
	default:
		http.Error(w, "Method not allowed.", http.StatusMethodNotAllowed)
		return
	}
	if r.Header.Get("Upgrade") != "" {
		http.Error(w, "Not supported.", http.StatusBadRequest)
		return
	}
	if !strings.Contains(r.URL.Path[len("/s/"):], "/") {
		// /s/<id> without the slash: the tool's own links need it.
		http.Redirect(w, r, s.prefix+"/", http.StatusPermanentRedirect)
		return
	}

	m.touch(s)
	r.Body = http.MaxBytesReader(w, r.Body, m.cfg.MaxUpload)

	// What the tool says about these is what counts; ours are the fallback in shape().
	for _, h := range []string{"Cache-Control", "Referrer-Policy", "X-Content-Type-Options"} {
		w.Header().Del(h)
	}

	target := &url.URL{Scheme: "http", Host: fmt.Sprintf("%s:%d", m.cfg.Upstream, s.port)}
	proxy := &httputil.ReverseProxy{
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.SetURL(target)
			pr.Out.Host = target.Host
			pr.Out.URL.Path = path
			pr.Out.URL.RawPath = ""
			pr.Out.URL.RawQuery = r.URL.RawQuery
			pr.Out.Header.Del("Authorization")
			pr.Out.Header.Del("Proxy-Authorization")
			pr.Out.Header.Del("Cookie")
			for _, c := range r.Cookies() {
				if hasPrefix(c.Name, cookiePrefixes[s.Tool]) {
					pr.Out.AddCookie(c)
				}
			}
		},
		ModifyResponse: func(resp *http.Response) error { return m.shape(s, target, resp) },
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			var tooBig *http.MaxBytesError
			if asMaxBytes(err, &tooBig) {
				http.Error(w, "That upload is too large.", http.StatusRequestEntityTooLarge)
				return
			}
			slog.Warn("database tool did not answer", "tool", s.Tool, "err", err)
			http.Error(w, "The tool did not answer.", http.StatusBadGateway)
		},
	}
	proxy.ServeHTTP(w, r)
}

func asMaxBytes(err error, target **http.MaxBytesError) bool {
	for err != nil {
		if e, ok := err.(*http.MaxBytesError); ok {
			*target = e
			return true
		}
		u, ok := err.(interface{ Unwrap() error })
		if !ok {
			return false
		}
		err = u.Unwrap()
	}
	return false
}

// shape makes what a tool answers safe to hand to the browser: only its own
// cookies, only below the session's path, redirects that stay inside it, and
// no hint of what it runs on.
func (m *Manager) shape(s *session, target *url.URL, resp *http.Response) error {
	h := resp.Header

	var keep []string
	for _, c := range resp.Cookies() {
		if !hasPrefix(c.Name, cookiePrefixes[s.Tool]) {
			continue
		}
		c.Path, c.Domain = s.prefix+"/", ""
		c.Secure, c.HttpOnly, c.SameSite = true, true, http.SameSiteStrictMode
		keep = append(keep, c.String())
	}
	h.Del("Set-Cookie")
	for _, c := range keep {
		h.Add("Set-Cookie", c)
	}

	if loc := h.Get("Location"); loc != "" {
		rewritten, ok := m.inside(s, target, loc)
		if !ok {
			// A tool that sends the browser elsewhere is not doing what it is here for.
			h.Del("Location")
			resp.StatusCode, resp.Status = http.StatusBadGateway, "502 Bad Gateway"
		} else {
			h.Set("Location", rewritten)
		}
	}

	// Headers that reach beyond the tool's own page: the browser keeps them for the whole host,
	// which the panel shares (a different port is not a different host to these).
	for _, name := range []string{
		"Server", "X-Powered-By", "Strict-Transport-Security", "Clear-Site-Data", "Refresh",
		"Content-Location", "Link", "Public-Key-Pins", "Alt-Svc", "Report-To", "Reporting-Endpoints",
		"Access-Control-Allow-Origin", "Access-Control-Allow-Credentials", "Access-Control-Allow-Headers",
		"Access-Control-Allow-Methods", "Access-Control-Expose-Headers", "Access-Control-Max-Age",
	} {
		h.Del(name)
	}
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Referrer-Policy", "no-referrer")
	if h.Get("X-Frame-Options") == "" {
		h.Set("X-Frame-Options", "DENY")
	}
	if h.Get("Content-Security-Policy") == "" {
		h.Set("Content-Security-Policy", "frame-ancestors 'none'")
	}
	if h.Get("Cache-Control") == "" {
		h.Set("Cache-Control", "no-store")
	}
	return nil
}

// inside turns a redirect target into one below the session's path, or reports
// that it points somewhere else. Anything that names a host (with or without a
// scheme: //host/path counts) has to be the tool itself or this session's own
// address; a bare path is taken to mean a path of the tool.
func (m *Manager) inside(s *session, target *url.URL, loc string) (string, bool) {
	// Browsers read a backslash as a slash, so \/host would be //host.
	if strings.ContainsAny(loc, "\\\t\r\n") {
		return "", false
	}
	u, err := url.Parse(loc)
	if err != nil || u.User != nil {
		return "", false
	}
	if u.IsAbs() || u.Host != "" {
		public, perr := url.Parse(s.baseURL)
		switch {
		case u.Host == target.Host && (u.Scheme == "http" || u.Scheme == ""):
			// The tool talked about itself by its own address.
		case perr == nil && u.Host == public.Host && (u.Scheme == public.Scheme || u.Scheme == "") && strings.HasPrefix(u.Path, s.prefix+"/"):
			return u.RequestURI(), true
		default:
			return "", false
		}
		u = &url.URL{Path: u.Path, RawQuery: u.RawQuery}
	}
	if u.Scheme != "" || strings.HasPrefix(u.Path, "//") {
		return "", false
	}
	if strings.HasPrefix(u.Path, "/") && !strings.HasPrefix(u.Path, s.prefix+"/") {
		u.Path = s.prefix + u.Path
	}
	return u.String(), true
}
