package docker

import (
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/url"
	"os"
	"strings"
	"time"

	"commypanel/agent/internal/validate"
)

const (
	maxTaskArgs        = 64
	maxTaskArgBytes    = 4 << 10
	maxTaskCmdBytes    = 16 << 10
	defaultTaskTimeout = 600
	maxTaskTimeout     = 3600
	maxTaskOutput      = 4 << 20
	maxTaskLine        = 64 << 10
)

// TaskSpec is a one-off command run to completion in a fresh container from
// one of CommyPanel's own images, such as an app's release command. The
// command is an argument list passed to Docker as-is; no shell is involved
// unless the image's own command is one. The container gets the same fixed
// security settings as an app container and never publishes a port.
type TaskSpec struct {
	Name           string            `json:"name"`
	Image          string            `json:"image"`
	Command        []string          `json:"command"`
	Env            map[string]string `json:"env,omitempty"`
	Volumes        []Volume          `json:"volumes,omitempty"`
	MemoryMB       int               `json:"memory_mb,omitempty"`
	CPUs           float64           `json:"cpus,omitempty"`
	Network        string            `json:"network,omitempty"`
	TimeoutSeconds int               `json:"timeout_seconds,omitempty"`
}

func (s TaskSpec) container() ContainerSpec {
	return ContainerSpec{
		Name: s.Name, Image: s.Image, Env: s.Env, Volumes: s.Volumes,
		MemoryMB: s.MemoryMB, CPUs: s.CPUs, Network: s.Network,
	}
}

func (s TaskSpec) timeout() time.Duration {
	if s.TimeoutSeconds == 0 {
		return defaultTaskTimeout * time.Second
	}
	return time.Duration(s.TimeoutSeconds) * time.Second
}

// ValidateTask checks a task spec without touching Docker.
func (m *Manager) ValidateTask(s TaskSpec) error {
	if !strings.HasPrefix(s.Image, buildNamePrefix) {
		return &validate.InputError{Msg: "tasks only run from images CommyPanel built (" + buildNamePrefix + ")"}
	}
	if err := m.Validate(s.container()); err != nil {
		return err
	}
	if len(s.Command) == 0 || s.Command[0] == "" {
		return &validate.InputError{Msg: "command is required"}
	}
	if len(s.Command) > maxTaskArgs {
		return &validate.InputError{Msg: "command has too many arguments"}
	}
	total := 0
	for _, arg := range s.Command {
		if len(arg) > maxTaskArgBytes || strings.ContainsRune(arg, 0) {
			return &validate.InputError{Msg: "invalid command argument"}
		}
		total += len(arg)
	}
	if total > maxTaskCmdBytes {
		return &validate.InputError{Msg: "command is too long"}
	}
	if s.TimeoutSeconds < 0 || s.TimeoutSeconds > maxTaskTimeout {
		return &validate.InputError{Msg: fmt.Sprintf("timeout_seconds must be between 1 and %d", maxTaskTimeout)}
	}
	return nil
}

// RunTask runs the command to completion, passing every output line to
// onLine, and returns its exit code. The container is always removed
// afterwards, also when the command times out or the caller goes away.
func (m *Manager) RunTask(ctx context.Context, s TaskSpec, onLine func(string)) (int, error) {
	if err := m.ValidateTask(s); err != nil {
		return -1, err
	}
	if m.cfg.DryRun {
		onLine("[dry-run] would run " + strings.Join(s.Command, " ") + " in " + s.Image)
		return 0, nil
	}

	cleanup := context.WithoutCancel(ctx)
	ctx, cancel := context.WithTimeout(ctx, s.timeout())
	defer cancel()

	// A container left over from an interrupted run would block the name.
	if err := m.Remove(ctx, s.Name); err != nil {
		return -1, err
	}
	network := s.Network
	if network == "" {
		network = defaultNetwork
	}
	if err := m.ensureNetwork(ctx, network); err != nil {
		return -1, err
	}
	for _, v := range s.Volumes {
		if err := os.MkdirAll(v.Source, 0o755); err != nil {
			return -1, fmt.Errorf("create volume dir: %w", err)
		}
	}

	body := m.createBody(s.container())
	body["Cmd"] = s.Command
	body["HostConfig"].(map[string]any)["RestartPolicy"] = map[string]any{"Name": "no"}
	if err := m.engine.doJSON(ctx, "POST", "/containers/create", url.Values{"name": {s.Name}}, body, nil); err != nil {
		return -1, err
	}
	defer func() {
		_ = m.engine.doJSON(cleanup, "DELETE", "/containers/"+s.Name, url.Values{"force": {"true"}}, nil, nil)
	}()

	slog.Info("task started", "name", s.Name, "image", s.Image)
	if err := m.engine.doJSON(ctx, "POST", "/containers/"+s.Name+"/start", nil, nil, nil); err != nil {
		return -1, err
	}

	// Following the logs returns once the command has exited.
	logErr := m.followLogs(ctx, s.Name, onLine)
	if errors.Is(ctx.Err(), context.DeadlineExceeded) {
		return -1, fmt.Errorf("the command did not finish within %d seconds", int(s.timeout().Seconds()))
	}
	if logErr != nil {
		return -1, logErr
	}

	var result struct {
		StatusCode int `json:"StatusCode"`
	}
	if err := m.engine.doJSON(ctx, "POST", "/containers/"+s.Name+"/wait", nil, nil, &result); err != nil {
		return -1, err
	}
	slog.Info("task finished", "name", s.Name, "exit_code", result.StatusCode)
	return result.StatusCode, nil
}

func (m *Manager) followLogs(ctx context.Context, name string, onLine func(string)) error {
	resp, err := m.engine.do(ctx, "GET", "/containers/"+name+"/logs",
		url.Values{"follow": {"1"}, "stdout": {"1"}, "stderr": {"1"}}, nil, "")
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	lines := &lineWriter{onLine: onLine, limit: maxTaskOutput}
	err = demuxTo(resp.Body, lines)
	lines.flush()
	if err != nil && ctx.Err() == nil {
		return err
	}
	return nil
}

// demuxTo copies the payloads of Docker's multiplexed log stream (8-byte
// frame headers) to w until the stream ends.
func demuxTo(r io.Reader, w io.Writer) error {
	header := make([]byte, 8)
	for {
		if _, err := io.ReadFull(r, header); err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
		size := int64(binary.BigEndian.Uint32(header[4:]))
		if _, err := io.CopyN(w, r, size); err != nil {
			return err
		}
	}
}

// lineWriter splits written bytes into lines for onLine. Very long lines are
// split, and after limit bytes the rest of the output is dropped with a note.
type lineWriter struct {
	onLine    func(string)
	limit     int
	written   int
	truncated bool
	buf       bytes.Buffer
}

func (l *lineWriter) Write(p []byte) (int, error) {
	n := len(p)
	if l.truncated {
		return n, nil
	}
	if room := l.limit - l.written; len(p) > room {
		p = p[:room]
		l.truncated = true
	}
	l.written += len(p)
	l.buf.Write(p)
	for {
		i := bytes.IndexByte(l.buf.Bytes(), '\n')
		if i < 0 {
			break
		}
		l.emit(l.buf.Next(i + 1))
	}
	for l.buf.Len() >= maxTaskLine {
		l.emit(l.buf.Next(maxTaskLine))
	}
	if l.truncated {
		l.flush()
		l.onLine("[output truncated]")
	}
	return n, nil
}

func (l *lineWriter) flush() {
	if l.buf.Len() > 0 {
		l.emit(l.buf.Next(l.buf.Len()))
	}
}

func (l *lineWriter) emit(b []byte) {
	l.onLine(strings.TrimRight(string(b), "\r\n"))
}
