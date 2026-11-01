package docker

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"

	"commypanel/agent/internal/validate"
)

// shellCommand is what an interactive session runs. Its environment and user
// are the container's own: the shell is not privileged and is not root unless
// the app is.
var shellCommand = []string{"/bin/sh"}

// Shell is an interactive terminal inside a managed container.
type Shell struct {
	conn   net.Conn
	r      *bufio.Reader
	execID string
	m      *Manager
}

func (s *Shell) Read(p []byte) (int, error)  { return s.r.Read(p) }
func (s *Shell) Write(p []byte) (int, error) { return s.conn.Write(p) }

// Close ends the session. The shell exits when its terminal closes.
func (s *Shell) Close() error { return s.conn.Close() }

// Resize tells the container's terminal its new size.
func (s *Shell) Resize(cols, rows int) error {
	if cols < 1 || cols > 1000 || rows < 1 || rows > 1000 {
		return &validate.InputError{Msg: "invalid terminal size"}
	}
	q := url.Values{"w": {strconv.Itoa(cols)}, "h": {strconv.Itoa(rows)}}
	return s.m.engine.doJSON(context.Background(), "POST", "/exec/"+s.execID+"/resize", q, nil, nil)
}

// OpenShell starts a shell in a running managed container with a terminal.
// Containers the agent did not create are refused, like every other action.
func (m *Manager) OpenShell(ctx context.Context, name string, cols, rows int) (*Shell, error) {
	if err := validate.Name(name); err != nil {
		return nil, err
	}
	if cols < 1 || cols > 1000 || rows < 1 || rows > 1000 {
		return nil, &validate.InputError{Msg: "invalid terminal size"}
	}
	if m.cfg.DryRun {
		return nil, &validate.InputError{Msg: "terminals are not available in dry-run mode"}
	}
	if exists, err := m.requireManaged(ctx, name); err != nil {
		return nil, err
	} else if !exists {
		return nil, &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", name)}
	}

	var created struct {
		ID string `json:"Id"`
	}
	err := m.engine.doJSON(ctx, "POST", "/containers/"+name+"/exec", nil, map[string]any{
		"AttachStdin": true, "AttachStdout": true, "AttachStderr": true, "Tty": true,
		"Privileged": false, "Cmd": shellCommand, "Env": []string{"TERM=xterm-256color"},
	}, &created)
	if isStatus(err, 409) {
		return nil, &validate.InputError{Msg: "the container is not running"}
	}
	if err != nil {
		return nil, err
	}

	conn, r, err := m.engine.hijack(ctx, "/exec/"+created.ID+"/start", map[string]any{"Detach": false, "Tty": true})
	if err != nil {
		return nil, err
	}
	sh := &Shell{conn: conn, r: r, execID: created.ID, m: m}
	// The size can only be set once the exec is running.
	_ = sh.Resize(cols, rows)
	return sh, nil
}

// hijack POSTs a JSON body and, when the daemon switches protocols, hands back
// the raw connection.
func (e *Engine) hijack(ctx context.Context, path string, body any) (net.Conn, *bufio.Reader, error) {
	if e.dial == nil {
		return nil, nil, errors.New("this engine cannot open streams")
	}
	raw, err := json.Marshal(body)
	if err != nil {
		return nil, nil, err
	}
	conn, err := e.dial(ctx)
	if err != nil {
		return nil, nil, fmt.Errorf("%w: %v", ErrUnavailable, err)
	}
	prefix := ""
	if u, err := url.Parse(e.base); err == nil {
		prefix = u.Path
	}
	req, err := http.NewRequest("POST", "http://docker"+prefix+path, bytes.NewReader(raw))
	if err != nil {
		conn.Close()
		return nil, nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Connection", "Upgrade")
	req.Header.Set("Upgrade", "tcp")
	if err := req.Write(conn); err != nil {
		conn.Close()
		return nil, nil, fmt.Errorf("%w: %v", ErrUnavailable, err)
	}
	br := bufio.NewReader(conn)
	resp, err := http.ReadResponse(br, req)
	if err != nil {
		conn.Close()
		return nil, nil, fmt.Errorf("%w: %v", ErrUnavailable, err)
	}
	if resp.StatusCode != http.StatusSwitchingProtocols && resp.StatusCode != http.StatusOK {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		conn.Close()
		return nil, nil, &APIError{Status: resp.StatusCode, Message: string(bytes.TrimSpace(msg))}
	}
	return conn, br, nil
}
