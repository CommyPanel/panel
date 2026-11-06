// Package traffic keeps a rolling picture of how each app's containers are doing, from
// the requests nginx proxies to them: how many, how many failed, and how long they took.
//
// nginx writes one short line per request to a Unix datagram socket this package listens
// on (an `access_log syslog:` target), so there are no log files to rotate or fill a disk.
// Lines are counted per site and per container port in ten-second buckets, kept for an
// hour. The panel asks for the totals of a time range and compares versions with each
// other (a release against the one it replaced), which is what automatic rollback and
// gradual rollouts decide on. Nothing here is written to disk, and a restart starts empty.
package traffic

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"net"
	"os"
	"os/user"
	"strconv"
	"strings"
	"sync"
	"time"

	"commypanel/agent/internal/validate"
)

const (
	// BucketSeconds is how finely time is kept; History is how long.
	BucketSeconds = 10
	History       = time.Hour
	buckets       = int(History/time.Second) / BucketSeconds
	// maxKeys bounds memory: one key is a site on one container port.
	maxKeys = 2000
	// A datagram from nginx is far smaller; anything bigger is not ours.
	maxLine = 2048
)

// Latency bounds in milliseconds; the last bucket is everything slower.
var bounds = [...]float64{2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000}

type bucket struct {
	start    int64 // unix seconds, a multiple of BucketSeconds; 0 means unused
	requests uint32
	errors   uint32
	hist     [len(bounds) + 1]uint32
}

type key struct {
	site string
	port int
}

type series struct {
	ring    [buckets]bucket
	touched int64
}

// Store holds the counters. It is safe for concurrent use.
type Store struct {
	mu      sync.Mutex
	now     func() time.Time
	data    map[key]*series
	dropped uint64
}

// New makes an empty store. now may be nil.
func New(now func() time.Time) *Store {
	if now == nil {
		now = time.Now
	}
	return &Store{now: now, data: map[key]*series{}}
}

// Request is one proxied request, as nginx reported it.
type Request struct {
	Site     string
	Status   int
	Port     int     // the container port that answered (the last one tried)
	Duration float64 // seconds, the whole request
}

// Record counts a request. A 5xx counts as a failure of the container that answered.
func (s *Store) Record(r Request) {
	if r.Port < 1024 || r.Port > 65535 || r.Status < 100 || r.Status > 599 {
		return
	}
	k := key{r.Site, r.Port}
	t := s.now().Unix()
	start := t - t%BucketSeconds

	s.mu.Lock()
	defer s.mu.Unlock()

	ser := s.data[k]
	if ser == nil {
		if len(s.data) >= maxKeys && !s.evictLocked(t) {
			s.dropped++
			return
		}
		ser = &series{}
		s.data[k] = ser
	}
	ser.touched = t

	b := &ser.ring[(start/BucketSeconds)%int64(buckets)]
	if b.start != start {
		*b = bucket{start: start}
	}
	b.requests++
	if r.Status >= 500 {
		b.errors++
	}
	b.hist[slot(r.Duration*1000)]++
}

// evictLocked frees one key that has been idle longest, if it has been quiet for an hour.
func (s *Store) evictLocked(now int64) bool {
	var oldest key
	var at int64 = now
	found := false
	for k, ser := range s.data {
		if ser.touched < at {
			oldest, at, found = k, ser.touched, true
		}
	}
	if !found || now-at < int64(History/time.Second) {
		return false
	}
	delete(s.data, oldest)
	return true
}

func slot(ms float64) int {
	for i, b := range bounds {
		if ms <= b {
			return i
		}
	}
	return len(bounds)
}

// Stats is the picture of one container port over a time range.
type Stats struct {
	Requests uint64  `json:"requests"`
	Errors   uint64  `json:"errors"`
	P50      float64 `json:"p50_ms"`
	P95      float64 `json:"p95_ms"`
	P99      float64 `json:"p99_ms"`
	// Series is the same counts over time, when asked for.
	Series []Point `json:"series,omitempty"`
}

// Point is one step of a series.
type Point struct {
	T        int64  `json:"t"` // unix seconds, start of the step
	Requests uint64 `json:"requests"`
	Errors   uint64 `json:"errors"`
}

// Query is a time range and the size of a series step (0 for no series).
type Query struct {
	From, To int64 // unix seconds; the range is [From, To)
	Step     int64 // seconds, a multiple of BucketSeconds
}

// Range returns the totals per container port of a site over the range.
func (s *Store) Range(site string, q Query) map[int]*Stats {
	if q.To <= q.From {
		return map[int]*Stats{}
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	out := map[int]*Stats{}
	cutoff := s.now().Unix() - int64(History/time.Second)
	for k, ser := range s.data {
		if k.site != site {
			continue
		}
		st, any := aggregate(ser, q, cutoff)
		if any {
			out[k.port] = st
		}
	}
	return out
}

func aggregate(ser *series, q Query, cutoff int64) (*Stats, bool) {
	st := &Stats{}
	var hist [len(bounds) + 1]uint64
	var points map[int64]*Point
	if q.Step >= BucketSeconds {
		points = map[int64]*Point{}
	}
	any := false
	for i := range ser.ring {
		b := &ser.ring[i]
		if b.start == 0 || b.start < cutoff || b.start < q.From || b.start >= q.To || b.requests == 0 {
			continue
		}
		any = true
		st.Requests += uint64(b.requests)
		st.Errors += uint64(b.errors)
		for j, n := range b.hist {
			hist[j] += uint64(n)
		}
		if points != nil {
			t := b.start - (b.start-q.From)%q.Step
			p := points[t]
			if p == nil {
				p = &Point{T: t}
				points[t] = p
			}
			p.Requests += uint64(b.requests)
			p.Errors += uint64(b.errors)
		}
	}
	st.P50, st.P95, st.P99 = percentile(hist[:], st.Requests, 50), percentile(hist[:], st.Requests, 95), percentile(hist[:], st.Requests, 99)
	if points != nil {
		for t := q.From; t < q.To; t += q.Step {
			if p := points[t]; p != nil {
				st.Series = append(st.Series, *p)
			} else {
				st.Series = append(st.Series, Point{T: t})
			}
		}
	}
	return st, any
}

// percentile gives the upper bound of the latency bucket the percentile falls in, in
// milliseconds. It is coarse on purpose: it is for comparing versions, not for billing.
func percentile(hist []uint64, total uint64, p int) float64 {
	if total == 0 {
		return 0
	}
	want := (total*uint64(p) + 99) / 100
	var seen uint64
	for i, n := range hist {
		seen += n
		if seen >= want {
			if i < len(bounds) {
				return bounds[i]
			}
			return bounds[len(bounds)-1] * 2
		}
	}
	return bounds[len(bounds)-1] * 2
}

// Dropped is how many requests were not counted because the store was full.
func (s *Store) Dropped() uint64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.dropped
}

// ParseLine reads one datagram. nginx's syslog target prefixes a priority and a tag
// ("<190>Nov  6 12:00:00 nginx: "); the part after the first ": " is our own format:
//
//	site|status|request_time|upstream_addr|upstream_status|upstream_response_time
//
// where the upstream fields can list several attempts ("a, b"). The container that
// answered is the last one named. A request no container answered (nginx's own 301, an
// ACME challenge) has no upstream and is not counted.
func ParseLine(line []byte) (Request, bool) {
	if len(line) == 0 || len(line) > maxLine {
		return Request{}, false
	}
	if i := bytes.Index(line, []byte(": ")); i >= 0 && line[0] == '<' {
		line = line[i+2:]
	}
	parts := strings.Split(strings.TrimSpace(string(line)), "|")
	if len(parts) != 6 {
		return Request{}, false
	}
	site := parts[0]
	if validate.Name(site) != nil {
		return Request{}, false
	}
	status, err := strconv.Atoi(parts[1])
	if err != nil {
		return Request{}, false
	}
	dur, err := strconv.ParseFloat(parts[2], 64)
	if err != nil || dur < 0 || dur > 86400 {
		return Request{}, false
	}
	addrs := strings.Split(parts[3], ",")
	last := strings.TrimSpace(addrs[len(addrs)-1])
	i := strings.LastIndex(last, ":")
	if i < 0 || strings.HasPrefix(last, "unix:") {
		return Request{}, false // "-" or a socket: not one of our containers
	}
	host := last[:i]
	if host != "127.0.0.1" {
		return Request{}, false
	}
	port, err := strconv.Atoi(last[i+1:])
	if err != nil {
		return Request{}, false
	}
	return Request{Site: site, Status: status, Port: port, Duration: dur}, true
}

// Listen receives nginx's log lines on a Unix datagram socket until ctx ends. Only the
// web server's group may write to it. A line that does not parse is dropped.
func (s *Store) Listen(ctx context.Context, path, group string, dryRun bool) error {
	if err := os.MkdirAll(dirOf(path), 0o755); err != nil {
		return err
	}
	_ = os.Remove(path)
	conn, err := net.ListenPacket("unixgram", path)
	if err != nil {
		return err
	}
	if err := os.Chmod(path, 0o660); err != nil {
		conn.Close()
		return err
	}
	if g, err := user.LookupGroup(group); err == nil {
		gid, _ := strconv.Atoi(g.Gid)
		if err := os.Chown(path, -1, gid); err != nil && !dryRun {
			conn.Close()
			return err
		}
	} else if !dryRun {
		conn.Close()
		return err
	}
	go func() {
		<-ctx.Done()
		conn.Close()
		_ = os.Remove(path)
	}()
	go func() {
		buf := make([]byte, maxLine+1)
		for {
			n, _, err := conn.ReadFrom(buf)
			if err != nil {
				if !errors.Is(err, net.ErrClosed) && ctx.Err() == nil {
					slog.Error("traffic socket stopped", "err", err)
				}
				return
			}
			if req, ok := ParseLine(buf[:n]); ok {
				s.Record(req)
			}
		}
	}()
	return nil
}

func dirOf(path string) string {
	if i := strings.LastIndex(path, "/"); i > 0 {
		return path[:i]
	}
	return "."
}
