package docker

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"

	"commypanel/agent/internal/validate"
)

// execResult is what a command run inside a container printed and returned.
type execResult struct {
	Output   string
	ExitCode int
}

const (
	execTimeout   = 2 * time.Minute
	dumpTimeout   = 30 * time.Minute
	maxExecOutput = 1 << 20
)

// execIn runs one command inside a managed, running container without a
// terminal, feeds it stdin, and collects its output and exit code. The command
// is an argument list (no shell). Secrets belong in env and stdin, never in the
// arguments, where other processes in the container could read them.
func (m *Manager) execIn(ctx context.Context, container string, cmd, env []string, stdin string) (execResult, error) {
	if exists, err := m.requireManaged(ctx, container); err != nil {
		return execResult{}, err
	} else if !exists {
		return execResult{}, &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", container)}
	}

	ctx, cancel := context.WithTimeout(ctx, execTimeout)
	defer cancel()

	var created struct {
		ID string `json:"Id"`
	}
	err := m.engine.doJSON(ctx, "POST", "/containers/"+container+"/exec", nil, map[string]any{
		"AttachStdin": true, "AttachStdout": true, "AttachStderr": true, "Tty": false,
		"Privileged": false, "Cmd": cmd, "Env": env,
	}, &created)
	if isStatus(err, 409) {
		return execResult{}, &validate.InputError{Msg: "the container is not running"}
	}
	if err != nil {
		return execResult{}, err
	}

	conn, r, err := m.engine.hijack(ctx, "/exec/"+created.ID+"/start", map[string]any{"Detach": false, "Tty": false})
	if err != nil {
		return execResult{}, err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(execTimeout))

	if _, err := io.WriteString(conn, stdin); err != nil {
		return execResult{}, err
	}
	// Tell the command its input is complete.
	if cw, ok := conn.(interface{ CloseWrite() error }); ok {
		_ = cw.CloseWrite()
	}

	out := demuxAll(r)

	var inspect struct {
		ExitCode int  `json:"ExitCode"`
		Running  bool `json:"Running"`
	}
	if err := m.engine.doJSON(context.WithoutCancel(ctx), "GET", "/exec/"+created.ID+"/json", nil, nil, &inspect); err != nil {
		return execResult{}, err
	}
	return execResult{Output: out, ExitCode: inspect.ExitCode}, nil
}

// demuxAll reads a multiplexed (non-terminal) stream to its end, keeping at
// most maxExecOutput bytes.
func demuxAll(r *bufio.Reader) string {
	var out strings.Builder
	header := make([]byte, 8)
	for {
		if _, err := io.ReadFull(r, header); err != nil {
			break
		}
		size := int64(header[4])<<24 | int64(header[5])<<16 | int64(header[6])<<8 | int64(header[7])
		if out.Len() < maxExecOutput {
			_, _ = io.CopyN(&out, r, min(size, int64(maxExecOutput-out.Len())))
			if rest := size - int64(min(size, int64(maxExecOutput))); rest > 0 {
				_, _ = io.CopyN(io.Discard, r, rest)
			}
		} else {
			_, _ = io.CopyN(io.Discard, r, size)
		}
	}
	return out.String()
}

func asInput(err error, target **validate.InputError) bool {
	return errors.As(err, target)
}

// execStream is execIn for a command with a lot of output: stdout goes to w as
// it arrives, and only stderr (the messages) is kept, up to a limit.
func (m *Manager) execStream(ctx context.Context, container string, cmd, env []string, w io.Writer) (execResult, error) {
	if exists, err := m.requireManaged(ctx, container); err != nil {
		return execResult{}, err
	} else if !exists {
		return execResult{}, &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", container)}
	}

	ctx, cancel := context.WithTimeout(ctx, dumpTimeout)
	defer cancel()

	var created struct {
		ID string `json:"Id"`
	}
	err := m.engine.doJSON(ctx, "POST", "/containers/"+container+"/exec", nil, map[string]any{
		"AttachStdin": false, "AttachStdout": true, "AttachStderr": true, "Tty": false,
		"Privileged": false, "Cmd": cmd, "Env": env,
	}, &created)
	if isStatus(err, 409) {
		return execResult{}, &validate.InputError{Msg: "the container is not running"}
	}
	if err != nil {
		return execResult{}, err
	}

	conn, r, err := m.engine.hijack(ctx, "/exec/"+created.ID+"/start", map[string]any{"Detach": false, "Tty": false})
	if err != nil {
		return execResult{}, err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(dumpTimeout))

	var stderr strings.Builder
	header := make([]byte, 8)
	for {
		if _, err := io.ReadFull(r, header); err != nil {
			break
		}
		size := int64(header[4])<<24 | int64(header[5])<<16 | int64(header[6])<<8 | int64(header[7])
		if header[0] == 2 { // stderr
			keep := min(size, int64(maxExecOutput-stderr.Len()))
			if keep > 0 {
				_, _ = io.CopyN(&stderr, r, keep)
			}
			if size > keep {
				_, _ = io.CopyN(io.Discard, r, size-keep)
			}
			continue
		}
		if _, err := io.CopyN(w, r, size); err != nil {
			return execResult{}, fmt.Errorf("writing the output: %w", err)
		}
	}

	var inspect struct {
		ExitCode int `json:"ExitCode"`
	}
	if err := m.engine.doJSON(context.WithoutCancel(ctx), "GET", "/exec/"+created.ID+"/json", nil, nil, &inspect); err != nil {
		return execResult{}, err
	}
	return execResult{Output: stderr.String(), ExitCode: inspect.ExitCode}, nil
}
