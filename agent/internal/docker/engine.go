// Package docker performs container operations through the Docker Engine API
// over its Unix socket, using only the standard library.
package docker

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
)

const apiVersion = "v1.43"

// ErrUnavailable means the Docker daemon could not be reached.
var ErrUnavailable = errors.New("docker is not available")

// APIError is an error response from the Docker daemon.
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("docker: %s (HTTP %d)", e.Message, e.Status) }

// Engine is a minimal Docker Engine API client.
type Engine struct {
	base string
	hc   *http.Client
	// dial opens a raw connection to the daemon, for the few calls that turn
	// into a bidirectional stream (an interactive exec).
	dial func(ctx context.Context) (net.Conn, error)
}

// NewUnixEngine talks to the daemon at the given Unix socket.
func NewUnixEngine(socket string) *Engine {
	tr := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{}).DialContext(ctx, "unix", socket)
		},
	}
	return &Engine{
		base: "http://docker/" + apiVersion,
		hc:   &http.Client{Transport: tr},
		dial: func(ctx context.Context) (net.Conn, error) { return (&net.Dialer{}).DialContext(ctx, "unix", socket) },
	}
}

// NewEngine talks to a daemon at an arbitrary URL (used by tests).
func NewEngine(baseURL string, hc *http.Client) *Engine {
	if hc == nil {
		hc = http.DefaultClient
	}
	e := &Engine{base: strings.TrimRight(baseURL, "/"), hc: hc}
	if u, err := url.Parse(e.base); err == nil {
		e.dial = func(ctx context.Context) (net.Conn, error) { return (&net.Dialer{}).DialContext(ctx, "tcp", u.Host) }
	}
	return e
}

// do sends a request. The caller must close the response body. Responses with
// status >= 400 are converted to *APIError (and the body is closed).
func (e *Engine) do(ctx context.Context, method, path string, query url.Values, body io.Reader, contentType string) (*http.Response, error) {
	u := e.base + path
	if len(query) > 0 {
		u += "?" + query.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, method, u, body)
	if err != nil {
		return nil, err
	}
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	resp, err := e.hc.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		return nil, fmt.Errorf("%w: %v", ErrUnavailable, err)
	}
	if resp.StatusCode >= 400 {
		defer resp.Body.Close()
		var payload struct {
			Message string `json:"message"`
		}
		raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		_ = json.Unmarshal(raw, &payload)
		if payload.Message == "" {
			payload.Message = strings.TrimSpace(string(raw))
		}
		return nil, &APIError{Status: resp.StatusCode, Message: payload.Message}
	}
	return resp, nil
}

// doJSON sends an optional JSON body and decodes an optional JSON response.
func (e *Engine) doJSON(ctx context.Context, method, path string, query url.Values, in, out any) error {
	var body io.Reader
	contentType := ""
	if in != nil {
		raw, err := json.Marshal(in)
		if err != nil {
			return err
		}
		body = strings.NewReader(string(raw))
		contentType = "application/json"
	}
	resp, err := e.do(ctx, method, path, query, body, contentType)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if out == nil {
		_, _ = io.Copy(io.Discard, resp.Body)
		return nil
	}
	return json.NewDecoder(resp.Body).Decode(out)
}

func isStatus(err error, status int) bool {
	var apiErr *APIError
	return errors.As(err, &apiErr) && apiErr.Status == status
}
