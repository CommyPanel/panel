package docker

import (
	"context"
	"net/url"
	"sort"
	"sync"
	"time"
)

const maxStatsConcurrency = 6

// RawStats is one reading of a running managed container's counters. CPU and
// network figures are cumulative; rates come from comparing two readings.
type RawStats struct {
	Name       string
	At         time.Time
	CPUTotal   uint64 // container CPU time, ns
	SystemCPU  uint64 // host CPU time, ns
	OnlineCPUs int
	MemUsed    uint64 // without reclaimable page cache, like `docker stats`
	MemLimit   uint64
	NetRx      uint64
	NetTx      uint64
}

// Stats reads the counters of every running managed container. It asks the
// daemon for a single sample per container (no waiting for Docker's own
// two-sample CPU window), so it returns quickly; a container that vanishes
// or fails to answer is left out. Read-only.
func (m *Manager) Stats(ctx context.Context) ([]RawStats, error) {
	if m.cfg.DryRun {
		return nil, nil
	}
	containers, err := m.List(ctx)
	if err != nil {
		return nil, err
	}

	var (
		mu  sync.Mutex
		out []RawStats
		wg  sync.WaitGroup
		sem = make(chan struct{}, maxStatsConcurrency)
	)
	for _, c := range containers {
		if c.State != "running" {
			continue
		}
		wg.Add(1)
		sem <- struct{}{}
		go func(name string) {
			defer wg.Done()
			defer func() { <-sem }()
			if s, ok := m.containerStats(ctx, name); ok {
				mu.Lock()
				out = append(out, s)
				mu.Unlock()
			}
		}(c.Name)
	}
	wg.Wait()

	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

func (m *Manager) containerStats(ctx context.Context, name string) (RawStats, bool) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	var raw struct {
		CPU struct {
			Usage struct {
				Total  uint64   `json:"total_usage"`
				PerCPU []uint64 `json:"percpu_usage"`
			} `json:"cpu_usage"`
			System uint64 `json:"system_cpu_usage"`
			Online int    `json:"online_cpus"`
		} `json:"cpu_stats"`
		Memory struct {
			Usage uint64            `json:"usage"`
			Limit uint64            `json:"limit"`
			Stats map[string]uint64 `json:"stats"`
		} `json:"memory_stats"`
		Networks map[string]struct {
			Rx uint64 `json:"rx_bytes"`
			Tx uint64 `json:"tx_bytes"`
		} `json:"networks"`
	}
	err := m.engine.doJSON(ctx, "GET", "/containers/"+name+"/stats",
		url.Values{"stream": {"false"}, "one-shot": {"true"}}, nil, &raw)
	if err != nil {
		return RawStats{}, false
	}

	online := raw.CPU.Online
	if online == 0 {
		online = len(raw.CPU.Usage.PerCPU)
	}
	mem := raw.Memory.Usage
	// Reclaimable page cache is not the app's memory: cgroup v2 reports it as
	// inactive_file, cgroup v1 as total_inactive_file (or cache).
	for _, key := range []string{"inactive_file", "total_inactive_file", "cache"} {
		if cache, ok := raw.Memory.Stats[key]; ok {
			if cache < mem {
				mem -= cache
			}
			break
		}
	}

	s := RawStats{
		Name: name, At: time.Now(), CPUTotal: raw.CPU.Usage.Total, SystemCPU: raw.CPU.System,
		OnlineCPUs: online, MemUsed: mem, MemLimit: raw.Memory.Limit,
	}
	for _, n := range raw.Networks {
		s.NetRx += n.Rx
		s.NetTx += n.Tx
	}
	return s, true
}
