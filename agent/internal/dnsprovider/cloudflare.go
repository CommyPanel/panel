// Package dnsprovider publishes the TXT records of the ACME DNS-01 challenge at a DNS host. Only
// Cloudflare is supported. The provider can do exactly two things: create a TXT record named
// _acme-challenge.<name>, and delete a record it created itself in this run. It cannot list,
// change or delete anything else, whatever the caller asks.
package dnsprovider

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"time"
)

const (
	// CloudflareAPI is the only address the provider talks to; tests may point it elsewhere.
	CloudflareAPI = "https://api.cloudflare.com/client/v4"

	maxResponse     = 1 << 20
	maxZoneTries    = 6
	challengePrefix = "_acme-challenge."
)

var (
	tokenRe = regexp.MustCompile(`^[A-Za-z0-9_-]{20,80}$`)
	nameRe  = regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$`)
)

// Error is a failure with a sentence for the person. Rejected means the token, its rights or the
// domain are the problem (something the person can fix), not Cloudflare being unreachable or busy.
type Error struct {
	Msg      string
	Rejected bool
}

func (e *Error) Error() string { return e.Msg }

func rejected(msg string) error { return &Error{Msg: msg, Rejected: true} }

// ErrToken marks a token that is not shaped like a Cloudflare API token.
var ErrToken = errors.New("that does not look like a Cloudflare API token")

// Provider is what the certificate manager needs from a DNS host.
type Provider interface {
	// Present creates the TXT record of a challenge and returns what is needed to remove it.
	Present(ctx context.Context, name, value string) (Record, error)
	// Cleanup removes a record that Present made. It is safe to call more than once.
	Cleanup(ctx context.Context, rec Record) error
}

// Record is a TXT record the provider made.
type Record struct {
	Zone string
	ID   string
}

// Config sets up a Cloudflare client.
type Config struct {
	Token   string
	BaseURL string // empty: CloudflareAPI
	Client  *http.Client
	// Propagation is how long to wait after creating a record before the CA is told to look.
	Propagation time.Duration
	Sleep       func(ctx context.Context, d time.Duration) error
}

// Cloudflare is a Provider (and a token checker) for one API token.
type Cloudflare struct {
	token   string
	base    string
	client  *http.Client
	wait    time.Duration
	sleep   func(ctx context.Context, d time.Duration) error
	mu      sync.Mutex
	created map[string]string // record id -> zone id, only what this client made
	zones   map[string]string // zone name -> zone id
}

// NewCloudflare checks the token's shape and builds the client. The token is never part of an
// error message or a log line.
func NewCloudflare(cfg Config) (*Cloudflare, error) {
	if !tokenRe.MatchString(cfg.Token) {
		return nil, ErrToken
	}
	base := strings.TrimRight(cfg.BaseURL, "/")
	if base == "" {
		base = CloudflareAPI
	}
	if !strings.HasPrefix(base, "https://") && !strings.HasPrefix(base, "http://127.0.0.1") {
		return nil, errors.New("the DNS provider address must be https")
	}
	c := &Cloudflare{token: cfg.Token, base: base, wait: cfg.Propagation, sleep: cfg.Sleep, created: map[string]string{}, zones: map[string]string{}}
	c.client = &http.Client{Timeout: 30 * time.Second}
	if cfg.Client != nil {
		copied := *cfg.Client
		c.client = &copied
	}
	// Redirects are never followed: the token must not be sent anywhere else.
	c.client.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	if c.sleep == nil {
		c.sleep = func(ctx context.Context, d time.Duration) error {
			t := time.NewTimer(d)
			defer t.Stop()
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-t.C:
				return nil
			}
		}
	}
	return c, nil
}

// DefaultPropagation is how long the CA is kept waiting after a record is made.
const DefaultPropagation = 10 * time.Second

// Present creates _acme-challenge.<name> with the value, in the zone of name.
func (c *Cloudflare) Present(ctx context.Context, name, value string) (Record, error) {
	if len(name) > 240 || !nameRe.MatchString(name) {
		return Record{}, errors.New("invalid challenge name")
	}
	if value == "" || len(value) > 255 || strings.ContainsAny(value, "\r\n\"") {
		return Record{}, errors.New("invalid challenge value")
	}
	_, zoneID, err := c.zone(ctx, name)
	if err != nil {
		return Record{}, err
	}
	var out struct {
		ID string `json:"id"`
	}
	err = c.call(ctx, http.MethodPost, "/zones/"+url.PathEscape(zoneID)+"/dns_records", map[string]any{
		"type": "TXT", "name": challengePrefix + name, "content": value, "ttl": 120,
	}, &out)
	if err != nil {
		return Record{}, err
	}
	if out.ID == "" {
		return Record{}, errors.New("Cloudflare did not say which record it made")
	}
	c.mu.Lock()
	c.created[out.ID] = zoneID
	c.mu.Unlock()
	if c.wait > 0 {
		if err := c.sleep(ctx, c.wait); err != nil {
			return Record{Zone: zoneID, ID: out.ID}, err
		}
	}
	return Record{Zone: zoneID, ID: out.ID}, nil
}

// Cleanup deletes a record this client made. Anything else is refused.
func (c *Cloudflare) Cleanup(ctx context.Context, rec Record) error {
	c.mu.Lock()
	zone, mine := c.created[rec.ID]
	c.mu.Unlock()
	if !mine || zone != rec.Zone {
		return nil // not ours, or already removed: nothing to do
	}
	err := c.call(ctx, http.MethodDelete, "/zones/"+url.PathEscape(zone)+"/dns_records/"+url.PathEscape(rec.ID), nil, nil)
	if err != nil {
		return err
	}
	c.mu.Lock()
	delete(c.created, rec.ID)
	c.mu.Unlock()
	return nil
}

// Check finds the zone that holds name and makes sure the token may read its DNS records. It
// changes nothing. It returns the zone's name.
func (c *Cloudflare) Check(ctx context.Context, name string) (string, error) {
	zone, zoneID, err := c.zone(ctx, strings.TrimPrefix(name, "*."))
	if err != nil {
		return "", err
	}
	var list []json.RawMessage
	if err := c.call(ctx, http.MethodGet, "/zones/"+url.PathEscape(zoneID)+"/dns_records?type=TXT&per_page=1", nil, &list); err != nil {
		return "", err
	}
	return zone, nil
}

// zone finds the active zone for a name by trying the name itself and then each parent, down to
// two labels.
func (c *Cloudflare) zone(ctx context.Context, name string) (string, string, error) {
	labels := strings.Split(strings.TrimPrefix(name, "*."), ".")
	tries := 0
	for i := 0; i <= len(labels)-2 && tries < maxZoneTries; i++ {
		candidate := strings.Join(labels[i:], ".")
		c.mu.Lock()
		id, known := c.zones[candidate]
		c.mu.Unlock()
		if known {
			return candidate, id, nil
		}
		tries++
		var zones []struct {
			ID     string `json:"id"`
			Name   string `json:"name"`
			Status string `json:"status"`
		}
		if err := c.call(ctx, http.MethodGet, "/zones?name="+url.QueryEscape(candidate)+"&status=active", nil, &zones); err != nil {
			return "", "", err
		}
		for _, z := range zones {
			if strings.EqualFold(z.Name, candidate) && z.Status == "active" && z.ID != "" {
				c.mu.Lock()
				c.zones[candidate] = z.ID
				c.mu.Unlock()
				return candidate, z.ID, nil
			}
		}
	}
	return "", "", rejected(fmt.Sprintf("Cloudflare has no active zone for %s that this token can see. Check the domain is on Cloudflare and that the token may read it (Zone: Read)", name))
}

// call makes one API request and decodes result into out (when given).
func (c *Cloudflare) call(ctx context.Context, method, path string, body any, out any) error {
	var reader io.Reader
	if body != nil {
		raw, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(raw)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, reader)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.client.Do(req)
	if err != nil {
		return fmt.Errorf("could not reach Cloudflare: %s", c.redact(err.Error()))
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxResponse+1))
	if err != nil {
		return fmt.Errorf("could not read Cloudflare's answer: %s", c.redact(err.Error()))
	}
	if len(raw) > maxResponse {
		return errors.New("Cloudflare's answer was larger than expected")
	}
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		return errors.New("Cloudflare answered with a redirect, which is not followed")
	}
	var envelope struct {
		Success bool `json:"success"`
		Errors  []struct {
			Code int `json:"code"`
		} `json:"errors"`
		Result json.RawMessage `json:"result"`
	}
	_ = json.Unmarshal(raw, &envelope)
	if resp.StatusCode != http.StatusOK || !envelope.Success {
		return describe(resp.StatusCode, envelope.Errors)
	}
	if out != nil && len(envelope.Result) > 0 {
		if err := json.Unmarshal(envelope.Result, out); err != nil {
			return errors.New("Cloudflare's answer could not be read")
		}
	}
	return nil
}

func (c *Cloudflare) redact(s string) string { return strings.ReplaceAll(s, c.token, "[token]") }

// describe turns Cloudflare's failure into a sentence a person can act on. The raw body is never shown.
func describe(status int, errs []struct {
	Code int `json:"code"`
}) error {
	for _, e := range errs {
		switch e.Code {
		case 6003, 6111, 9103:
			// A token Cloudflare cannot even read (wrong format or unknown). Rights problems (403) are
			// told apart by the status below, because Cloudflare reuses codes for both.
			return rejected("Cloudflare does not accept this token (unknown, misspelled or revoked). Make a new one")
		}
	}
	switch {
	case status == http.StatusUnauthorized:
		return rejected("Cloudflare does not accept this token: it is invalid, expired or revoked. Make a new one")
	case status == http.StatusForbidden:
		return rejected("this token is not allowed to do that. It needs Zone: Read and DNS: Edit for the zone of the domain")
	case status == http.StatusTooManyRequests:
		return errors.New("Cloudflare says there are too many requests right now. Try again in a few minutes")
	case status >= 500:
		return errors.New("Cloudflare is having trouble right now. Try again in a few minutes")
	}
	return fmt.Errorf("Cloudflare refused the request (status %d)", status)
}
