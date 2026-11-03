package dbtool

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/cookiejar"
	"net/http/httputil"
	"net/url"
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

	landing := s.prefix + "/"
	var carry []*http.Cookie

	switch s.Tool {
	case "phpmyadmin":
		landing = s.prefix + "/index.php?route=/database/structure&db=" + url.QueryEscape(s.Database)
	case "adminer":
		ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
		defer cancel()
		cookies, query, err := m.adminerLogin(ctx, s)
		if err != nil {
			slog.Warn("could not log in to Adminer", "server", s.Server, "err", err)
			http.Error(w, "The tool did not accept the login. Open the database from CommyPanel again.", http.StatusBadGateway)
			return
		}
		carry = cookies
		landing = s.prefix + "/" + query
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

// adminerLogin logs in to Adminer from here, so the database user's password
// never passes through the browser. It returns the cookies Adminer made and the
// query string it sends a logged-in person to.
func (m *Manager) adminerLogin(ctx context.Context, s *session) ([]*http.Cookie, string, error) {
	jar, _ := cookiejar.New(nil)
	client := &http.Client{Jar: jar, Timeout: 15 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	base := fmt.Sprintf("http://%s:%d/", m.cfg.Upstream, s.port)

	get, _ := http.NewRequestWithContext(ctx, http.MethodGet, base, nil)
	resp, err := client.Do(get)
	if err != nil {
		return nil, "", err
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))
	resp.Body.Close()

	form := url.Values{
		"auth[driver]":   {"pgsql"},
		"auth[server]":   {"db-" + s.Server},
		"auth[username]": {s.user},
		"auth[password]": {s.password},
		"auth[db]":       {s.Database},
	}
	post, _ := http.NewRequestWithContext(ctx, http.MethodPost, base, strings.NewReader(form.Encode()))
	post.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err = client.Do(post)
	if err != nil {
		return nil, "", err
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))
	resp.Body.Close()

	location := resp.Header.Get("Location")
	if resp.StatusCode != http.StatusFound || !strings.HasPrefix(location, "?") {
		return nil, "", fmt.Errorf("unexpected answer to the login (%d)", resp.StatusCode)
	}

	u, _ := url.Parse(base)
	var out []*http.Cookie
	for _, c := range jar.Cookies(u) {
		if hasPrefix(c.Name, cookiePrefixes["adminer"]) {
			out = append(out, c)
		}
	}
	if len(out) == 0 {
		return nil, "", fmt.Errorf("Adminer set no session")
	}
	return out, location, nil
}

// proxy passes a request to the session's tool, after checking the browser
// belongs to it. Whatever is wrong, the answer is the same plain 404.
func (m *Manager) proxy(w http.ResponseWriter, r *http.Request) {
	rest := strings.TrimPrefix(r.URL.Path, "/s/")
	id, path, _ := strings.Cut(rest, "/")
	path = "/" + path

	m.mu.Lock()
	s := m.sessions[id]
	m.mu.Unlock()
	if s == nil || s.Stage != StageReady {
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

	h.Del("Server")
	h.Del("X-Powered-By")
	h.Set("X-Content-Type-Options", "nosniff")
	h.Set("Referrer-Policy", "no-referrer")
	if h.Get("X-Frame-Options") == "" {
		h.Set("X-Frame-Options", "DENY")
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
