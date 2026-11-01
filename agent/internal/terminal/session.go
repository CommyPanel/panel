package terminal

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strconv"
	"sync/atomic"
	"time"

	"commypanel/agent/internal/validate"
)

// Shell is a terminal in a container.
type Shell interface {
	io.ReadWriteCloser
	Resize(cols, rows int) error
}

// OpenFunc starts a shell in a managed container.
type OpenFunc func(ctx context.Context, container string, cols, rows int) (Shell, error)

// Limits bound what one browser connection and all of them together can do.
type Limits struct {
	MaxSessions int           // at once
	Idle        time.Duration // without any input or output
	Duration    time.Duration // in total
}

// DefaultLimits suit an administrator working in a container.
var DefaultLimits = Limits{MaxSessions: 8, Idle: 15 * time.Minute, Duration: 2 * time.Hour}

// Server is the handler of the terminal socket. It serves one path and nothing else.
type Server struct {
	Tickets *Tickets
	Open    OpenFunc
	Limits  Limits

	active atomic.Int32
	// tick is how often the idle limit is checked; tests make it short.
	tick time.Duration
}

// Active is the number of sessions running now.
func (s *Server) Active() int { return int(s.active.Load()) }

// Handler serves GET /terminal?ticket=...&cols=..&rows=.. as a WebSocket.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /terminal", s.serve)
	return mux
}

type control struct {
	Type string `json:"type"`
	Cols int    `json:"cols"`
	Rows int    `json:"rows"`
}

func (s *Server) serve(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	ticket, ok := s.Tickets.Redeem(q.Get("ticket"))
	if !ok {
		// The same answer for a made-up, a used and an expired ticket.
		http.Error(w, "invalid or expired ticket", http.StatusForbidden)
		return
	}
	if int(s.active.Add(1)) > s.Limits.MaxSessions {
		s.active.Add(-1)
		http.Error(w, "too many open terminals", http.StatusServiceUnavailable)
		return
	}
	defer s.active.Add(-1)

	conn, err := Upgrade(w, r, func(origin string) bool { return origin == ticket.Origin })
	if err != nil {
		slog.Warn("terminal handshake refused", "container", ticket.Container, "err", err)
		return
	}
	cols, rows := size(q.Get("cols"), 80), size(q.Get("rows"), 24)

	started := time.Now()
	ctx, cancel := context.WithTimeout(r.Context(), s.Limits.Duration)
	defer cancel()

	shell, err := s.Open(ctx, ticket.Container, cols, rows)
	if err != nil {
		var input *validate.InputError
		msg := "The terminal could not be opened."
		if errors.As(err, &input) {
			msg = input.Msg
		} else {
			slog.Error("opening a terminal failed", "container", ticket.Container, "err", err)
		}
		report, _ := json.Marshal(map[string]string{"type": "error", "message": msg})
		_ = conn.WriteText(report)
		_ = conn.Close(CloseNormal, "")
		return
	}
	defer shell.Close()

	slog.Info("terminal opened", "container", ticket.Container)
	var in, out atomic.Int64
	last := atomic.Int64{}
	last.Store(time.Now().UnixNano())
	touch := func() { last.Store(time.Now().UnixNano()) }

	// Ends the session from the side that notices first.
	done := make(chan string, 3)

	go func() { // container -> browser
		buf := make([]byte, 8192)
		for {
			n, err := shell.Read(buf)
			if n > 0 {
				touch()
				out.Add(int64(n))
				if werr := conn.WriteBinary(buf[:n]); werr != nil {
					done <- "browser gone"
					return
				}
			}
			if err != nil {
				done <- "shell exited"
				return
			}
		}
	}()

	go func() { // browser -> container
		for {
			text, data, err := conn.ReadMessage()
			if err != nil {
				done <- "browser closed"
				return
			}
			touch()
			if !text {
				in.Add(int64(len(data)))
				if _, err := shell.Write(data); err != nil {
					done <- "shell exited"
					return
				}
				continue
			}
			var c control
			if json.Unmarshal(data, &c) == nil && c.Type == "resize" {
				_ = shell.Resize(clamp(c.Cols), clamp(c.Rows))
			}
		}
	}()

	go func() { // limits
		every := s.tick
		if every == 0 {
			every = 5 * time.Second
		}
		tick := time.NewTicker(every)
		defer tick.Stop()
		for {
			select {
			case <-ctx.Done():
				done <- "time limit"
				return
			case <-tick.C:
				if time.Since(time.Unix(0, last.Load())) > s.Limits.Idle {
					done <- "idle"
					return
				}
			}
		}
	}()

	reason := <-done
	switch reason {
	case "idle":
		_ = conn.Close(ClosePolicy, "closed after being idle")
	case "time limit":
		_ = conn.Close(ClosePolicy, "session time limit reached")
	case "shell exited":
		exit, _ := json.Marshal(map[string]string{"type": "exit"})
		_ = conn.WriteText(exit)
		_ = conn.Close(CloseNormal, "")
	default:
		_ = conn.Close(CloseNormal, "")
	}
	slog.Info("terminal closed", "container", ticket.Container, "reason", reason,
		"seconds", int(time.Since(started).Seconds()), "bytes_in", in.Load(), "bytes_out", out.Load())
}

func size(v string, fallback int) int {
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return clamp(n)
}

func clamp(n int) int { return max(1, min(n, 500)) }
