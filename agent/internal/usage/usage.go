// Package usage samples how busy the host and its managed containers are, and
// keeps a few minutes of history so a page opens with a full chart and then
// only has to ask for the newest reading. It is read-only.
//
// The host is sampled all the time (a few small /proc reads). Containers are
// sampled only while somebody is looking: a Snapshot marks the sampler as
// wanted for a while, and when nobody has asked for a minute Docker is left
// alone.
package usage

import (
	"bufio"
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"commypanel/agent/internal/docker"
)

const (
	defaultInterval = 2 * time.Second
	defaultHistory  = 150 // five minutes at the default interval
	wantedFor       = time.Minute
)

// ContainerSource reads the counters of the running managed containers.
type ContainerSource interface {
	Stats(ctx context.Context) ([]docker.RawStats, error)
}

// Config sets where readings come from. The zero value of each field falls
// back to the real host.
type Config struct {
	ProcDir    string
	DataDir    string // the filesystem whose free space is reported
	Interval   time.Duration
	History    int
	Containers ContainerSource
	Now        func() time.Time
}

// HostSample is one point of host history. Rx and Tx are bytes per second.
type HostSample struct {
	T   int64   `json:"t"` // unix milliseconds
	CPU float64 `json:"cpu"`
	Mem uint64  `json:"mem"`
	Rx  float64 `json:"rx"`
	Tx  float64 `json:"tx"`
}

// HostNow is the latest host reading.
type HostNow struct {
	// Ready is false until two readings exist, because CPU and network are rates.
	Ready      bool    `json:"ready"`
	CPUPercent float64 `json:"cpu_percent"`
	CPUs       int     `json:"cpus"`
	MemTotal   uint64  `json:"mem_total"`
	MemUsed    uint64  `json:"mem_used"`
	DiskTotal  uint64  `json:"disk_total"`
	DiskUsed   uint64  `json:"disk_used"`
	NetRxBps   float64 `json:"net_rx_bps"`
	NetTxBps   float64 `json:"net_tx_bps"`
}

// ContainerSample is one point of a container's history.
type ContainerSample struct {
	T   int64   `json:"t"`
	CPU float64 `json:"cpu"` // percent of one core
	Mem uint64  `json:"mem"`
	Rx  float64 `json:"rx"`
	Tx  float64 `json:"tx"`
}

// ContainerUsage is a container's latest reading.
type ContainerUsage struct {
	Name       string            `json:"name"`
	T          int64             `json:"t"`
	CPUPercent float64           `json:"cpu_percent"` // percent of one core; 100 = one full core
	MemUsed    uint64            `json:"mem_used"`
	MemLimit   uint64            `json:"mem_limit"`
	NetRxBps   float64           `json:"net_rx_bps"`
	NetTxBps   float64           `json:"net_tx_bps"`
	History    []ContainerSample `json:"history,omitempty"`
}

// Snapshot is what the panel asks for.
type Snapshot struct {
	IntervalMS  int64            `json:"interval_ms"`
	Now         int64            `json:"now"`
	Host        HostNow          `json:"host"`
	HostHistory []HostSample     `json:"host_history,omitempty"`
	Containers  []ContainerUsage `json:"containers"`
}

// Sampler takes the readings.
type Sampler struct {
	cfg Config

	mu         sync.Mutex
	wantedTill time.Time

	cpuPrev      cpuTimes
	netPrev      netTotals
	hostPrevAt   time.Time
	hostNow      HostNow
	hostHistory  *ring[HostSample]
	cpus         int
	containerRaw map[string]docker.RawStats
	containers   map[string]*containerState
}

type containerState struct {
	latest  ContainerUsage
	history *ring[ContainerSample]
}

func New(cfg Config) *Sampler {
	if cfg.ProcDir == "" {
		cfg.ProcDir = "/proc"
	}
	if cfg.Interval <= 0 {
		cfg.Interval = defaultInterval
	}
	if cfg.History <= 0 {
		cfg.History = defaultHistory
	}
	if cfg.Now == nil {
		cfg.Now = time.Now
	}
	return &Sampler{
		cfg:          cfg,
		hostHistory:  newRing[HostSample](cfg.History),
		containerRaw: map[string]docker.RawStats{},
		containers:   map[string]*containerState{},
	}
}

// Run samples until ctx ends.
func (s *Sampler) Run(ctx context.Context) {
	go s.runContainers(ctx)

	s.sampleHost(s.cfg.Now())
	tick := time.NewTicker(s.cfg.Interval)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			s.sampleHost(s.cfg.Now())
		}
	}
}

func (s *Sampler) runContainers(ctx context.Context) {
	if s.cfg.Containers == nil {
		return
	}
	tick := time.NewTicker(s.cfg.Interval)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			if !s.wanted() {
				s.forgetContainers()
				continue
			}
			raw, err := s.cfg.Containers.Stats(ctx)
			if err != nil {
				// Docker is down or not answering: showing the last readings would be a lie.
				slog.Debug("container stats unavailable", "err", err)
				s.forgetContainers()
				continue
			}
			s.sampleContainers(raw)
		}
	}
}

func (s *Sampler) wanted() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.cfg.Now().Before(s.wantedTill)
}

// forgetContainers drops container state once nobody is looking, so the next
// visitor does not see readings (and rates) from minutes ago.
func (s *Sampler) forgetContainers() {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.containers) > 0 {
		s.containers = map[string]*containerState{}
		s.containerRaw = map[string]docker.RawStats{}
	}
}

// Snapshot returns the latest readings, and the history when asked. Asking
// also keeps container sampling switched on for another minute.
func (s *Sampler) Snapshot(history bool) Snapshot {
	s.mu.Lock()
	defer s.mu.Unlock()

	now := s.cfg.Now()
	s.wantedTill = now.Add(wantedFor)

	snap := Snapshot{
		IntervalMS: s.cfg.Interval.Milliseconds(),
		Now:        now.UnixMilli(),
		Host:       s.hostNow,
		Containers: make([]ContainerUsage, 0, len(s.containers)),
	}
	if history {
		snap.HostHistory = s.hostHistory.all()
	}
	names := make([]string, 0, len(s.containers))
	for name := range s.containers {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		c := s.containers[name].latest
		if history {
			c.History = s.containers[name].history.all()
		}
		snap.Containers = append(snap.Containers, c)
	}
	return snap
}

// sampleHost takes one host reading. Anything unreadable (for example /proc on
// macOS during development) stays at zero.
func (s *Sampler) sampleHost(now time.Time) {
	cpu := readCPUTimes(filepath.Join(s.cfg.ProcDir, "stat"))
	net := readNetTotals(filepath.Join(s.cfg.ProcDir, "net/dev"))
	memTotal, memAvail := readMemory(filepath.Join(s.cfg.ProcDir, "meminfo"))
	diskTotal, diskUsed := diskUsage(s.cfg.DataDir)

	s.mu.Lock()
	defer s.mu.Unlock()

	if s.cpus == 0 {
		s.cpus = countCPUs(filepath.Join(s.cfg.ProcDir, "stat"))
	}
	host := HostNow{
		CPUs: s.cpus, MemTotal: memTotal, MemUsed: sub(memTotal, memAvail),
		DiskTotal: diskTotal, DiskUsed: diskUsed,
	}
	if !s.hostPrevAt.IsZero() {
		if dt := now.Sub(s.hostPrevAt).Seconds(); dt > 0 {
			host.Ready = true
			host.CPUPercent = cpu.percentSince(s.cpuPrev)
			host.NetRxBps = rate(net.rx, s.netPrev.rx, dt)
			host.NetTxBps = rate(net.tx, s.netPrev.tx, dt)
		}
	}
	s.cpuPrev, s.netPrev, s.hostPrevAt, s.hostNow = cpu, net, now, host

	if host.Ready {
		s.hostHistory.push(HostSample{
			T: now.UnixMilli(), CPU: host.CPUPercent, Mem: host.MemUsed, Rx: host.NetRxBps, Tx: host.NetTxBps,
		})
	}
}

// sampleContainers turns raw counters into rates by comparing them with the
// previous reading of the same container.
func (s *Sampler) sampleContainers(raw []docker.RawStats) {
	s.mu.Lock()
	defer s.mu.Unlock()

	seen := make(map[string]bool, len(raw))
	for _, r := range raw {
		seen[r.Name] = true
		prev, hadPrev := s.containerRaw[r.Name]
		s.containerRaw[r.Name] = r
		if !hadPrev {
			continue // rates need two readings
		}
		dt := r.At.Sub(prev.At).Seconds()
		if dt <= 0 {
			continue
		}

		u := ContainerUsage{
			Name: r.Name, T: r.At.UnixMilli(), MemUsed: r.MemUsed, MemLimit: r.MemLimit,
			NetRxBps: rate(r.NetRx, prev.NetRx, dt), NetTxBps: rate(r.NetTx, prev.NetTx, dt),
		}
		// Container CPU time over host CPU time, scaled by the core count, as `docker stats` does.
		if dSys := float64(sub(r.SystemCPU, prev.SystemCPU)); dSys > 0 {
			u.CPUPercent = float64(sub(r.CPUTotal, prev.CPUTotal)) / dSys * float64(max(r.OnlineCPUs, 1)) * 100
		}

		state := s.containers[r.Name]
		if state == nil {
			state = &containerState{history: newRing[ContainerSample](s.cfg.History)}
			s.containers[r.Name] = state
		}
		state.latest = u
		state.history.push(ContainerSample{T: u.T, CPU: u.CPUPercent, Mem: u.MemUsed, Rx: u.NetRxBps, Tx: u.NetTxBps})
	}
	for name := range s.containers {
		if !seen[name] {
			delete(s.containers, name)
			delete(s.containerRaw, name)
		}
	}
	for name := range s.containerRaw {
		if !seen[name] {
			delete(s.containerRaw, name)
		}
	}
}

// --- readers ---------------------------------------------------------------

type cpuTimes struct{ total, idle uint64 }

func (c cpuTimes) percentSince(prev cpuTimes) float64 {
	dTotal := sub(c.total, prev.total)
	if dTotal == 0 {
		return 0
	}
	busy := float64(dTotal-min(sub(c.idle, prev.idle), dTotal)) / float64(dTotal) * 100
	return min(max(busy, 0), 100)
}

// readCPUTimes parses the aggregate line of /proc/stat. Idle includes iowait.
func readCPUTimes(path string) cpuTimes {
	f, err := os.Open(path)
	if err != nil {
		return cpuTimes{}
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 5 || fields[0] != "cpu" {
			continue
		}
		var t cpuTimes
		for i, v := range fields[1:] {
			n, _ := strconv.ParseUint(v, 10, 64)
			if i < 8 { // user nice system idle iowait irq softirq steal; guest time is already in user
				t.total += n
			}
			if i == 3 || i == 4 {
				t.idle += n
			}
		}
		return t
	}
	return cpuTimes{}
}

func countCPUs(path string) int {
	f, err := os.Open(path)
	if err != nil {
		return 0
	}
	defer f.Close()
	n := 0
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		name, _, _ := strings.Cut(sc.Text(), " ")
		if strings.HasPrefix(name, "cpu") && len(name) > 3 {
			n++
		}
	}
	return n
}

type netTotals struct{ rx, tx uint64 }

// readNetTotals sums the traffic of the real network interfaces. Loopback and
// Docker's bridges and virtual pairs are left out: container traffic would
// otherwise be counted twice.
func readNetTotals(path string) netTotals {
	f, err := os.Open(path)
	if err != nil {
		return netTotals{}
	}
	defer f.Close()
	var t netTotals
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		name, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		name = strings.TrimSpace(name)
		if name == "lo" || name == "docker0" || strings.HasPrefix(name, "br-") ||
			strings.HasPrefix(name, "veth") || strings.HasPrefix(name, "virbr") {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) < 9 {
			continue
		}
		rx, _ := strconv.ParseUint(fields[0], 10, 64)
		tx, _ := strconv.ParseUint(fields[8], 10, 64)
		t.rx += rx
		t.tx += tx
	}
	return t
}

func readMemory(path string) (total, available uint64) {
	f, err := os.Open(path)
	if err != nil {
		return 0, 0
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		key, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}
		kb, err := strconv.ParseUint(fields[0], 10, 64)
		if err != nil {
			continue
		}
		switch key {
		case "MemTotal":
			total = kb * 1024
		case "MemAvailable":
			available = kb * 1024
		}
	}
	return total, available
}

func diskUsage(dir string) (total, used uint64) {
	for _, path := range []string{dir, "/"} {
		if path == "" {
			continue
		}
		var st syscall.Statfs_t
		if err := syscall.Statfs(path, &st); err == nil {
			bs := uint64(st.Bsize)
			total = uint64(st.Blocks) * bs
			return total, sub(total, uint64(st.Bavail)*bs)
		}
	}
	return 0, 0
}

// --- small helpers -----------------------------------------------------------

// sub is a-b that stops at zero, so a counter that went backwards (a reset,
// a container restart) reads as no change instead of a huge number.
func sub(a, b uint64) uint64 {
	if a < b {
		return 0
	}
	return a - b
}

func rate(now, prev uint64, seconds float64) float64 {
	if seconds <= 0 {
		return 0
	}
	return float64(sub(now, prev)) / seconds
}

// ring is a fixed-size history that drops its oldest entry when full.
type ring[T any] struct {
	items []T
	next  int
	full  bool
}

func newRing[T any](size int) *ring[T] { return &ring[T]{items: make([]T, size)} }

func (r *ring[T]) push(v T) {
	r.items[r.next] = v
	r.next++
	if r.next == len(r.items) {
		r.next, r.full = 0, true
	}
}

// all returns the entries oldest first.
func (r *ring[T]) all() []T {
	if !r.full {
		return append([]T(nil), r.items[:r.next]...)
	}
	return append(append([]T(nil), r.items[r.next:]...), r.items[:r.next]...)
}
