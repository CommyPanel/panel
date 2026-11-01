package usage

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"commypanel/agent/internal/docker"
)

func writeProc(t *testing.T, dir, stat, netdev, meminfo string) {
	t.Helper()
	must := func(rel, body string) {
		p := filepath.Join(dir, rel)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	must("stat", stat)
	must("net/dev", netdev)
	must("meminfo", meminfo)
}

const netHeader = "Inter-|   Receive                                                |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n"

func netDev(eth0Rx, eth0Tx, dockerRx int) string {
	line := func(name string, rx, tx int) string {
		return "  " + name + ": " + itoa(rx) + " 1 0 0 0 0 0 0 " + itoa(tx) + " 1 0 0 0 0 0 0\n"
	}
	return netHeader + line("lo", 9999, 9999) + line("eth0", eth0Rx, eth0Tx) + line("docker0", dockerRx, dockerRx) +
		line("br-1a2b3c", dockerRx, dockerRx) + line("veth12ab", dockerRx, dockerRx)
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	s := ""
	for ; n > 0; n /= 10 {
		s = string(rune('0'+n%10)) + s
	}
	return s
}

func stat(user, idle, iowait int) string {
	return "cpu  " + itoa(user) + " 0 0 " + itoa(idle) + " " + itoa(iowait) + " 0 0 0 0 0\n" +
		"cpu0 1 0 0 1 0 0 0 0 0 0\ncpu1 1 0 0 1 0 0 0 0 0 0\nintr 1\n"
}

const meminfo = "MemTotal:        2048000 kB\nMemFree:          100000 kB\nMemAvailable:    1024000 kB\n"

func TestHostRatesComeFromTwoReadings(t *testing.T) {
	dir := t.TempDir()
	s := New(Config{ProcDir: dir, DataDir: dir})
	t0 := time.Unix(1000, 0)

	writeProc(t, dir, stat(100, 900, 0), netDev(1000, 500, 0), meminfo)
	s.sampleHost(t0)
	if s.Snapshot(false).Host.Ready {
		t.Fatal("a single reading cannot give rates")
	}

	// In 2s: 100 more busy ticks of 200 total (50% busy), 4000 bytes in, 1000 out.
	// Docker's own interfaces grew too and must not be counted.
	writeProc(t, dir, stat(200, 900, 100), netDev(5000, 1500, 70000), meminfo)
	s.sampleHost(t0.Add(2 * time.Second))

	h := s.Snapshot(false).Host
	if !h.Ready || h.CPUPercent != 50 {
		t.Errorf("cpu = %v ready=%v, want 50", h.CPUPercent, h.Ready)
	}
	if h.NetRxBps != 2000 || h.NetTxBps != 500 {
		t.Errorf("net = %v/%v, want 2000/500", h.NetRxBps, h.NetTxBps)
	}
	if h.CPUs != 2 || h.MemTotal != 2048000*1024 || h.MemUsed != 1024000*1024 {
		t.Errorf("host = %+v", h)
	}
	if h.DiskTotal == 0 || h.DiskUsed > h.DiskTotal {
		t.Errorf("disk = %d/%d", h.DiskUsed, h.DiskTotal)
	}
}

func TestACounterThatGoesBackwardsReadsAsNoChange(t *testing.T) {
	dir := t.TempDir()
	s := New(Config{ProcDir: dir, DataDir: dir})
	writeProc(t, dir, stat(100, 900, 0), netDev(9000, 9000, 0), meminfo)
	s.sampleHost(time.Unix(1000, 0))
	writeProc(t, dir, stat(50, 800, 0), netDev(10, 10, 0), meminfo) // e.g. an interface was recreated
	s.sampleHost(time.Unix(1002, 0))

	h := s.Snapshot(false).Host
	if h.NetRxBps != 0 || h.NetTxBps != 0 || h.CPUPercent != 0 {
		t.Errorf("after a reset: %+v", h)
	}
}

func TestMissingProcLeavesZeros(t *testing.T) {
	s := New(Config{ProcDir: filepath.Join(t.TempDir(), "nothing"), DataDir: t.TempDir()})
	s.sampleHost(time.Unix(1000, 0))
	s.sampleHost(time.Unix(1002, 0))

	h := s.Snapshot(true)
	if h.Host.CPUPercent != 0 || h.Host.MemTotal != 0 || h.Host.CPUs != 0 {
		t.Errorf("host = %+v", h.Host)
	}
}

func TestHistoryKeepsTheNewestInOrder(t *testing.T) {
	dir := t.TempDir()
	s := New(Config{ProcDir: dir, DataDir: dir, History: 3})
	for i := 0; i < 6; i++ {
		writeProc(t, dir, stat(100+i*10, 900+i*10, 0), netDev(1000*(i+1), 0, 0), meminfo)
		s.sampleHost(time.Unix(1000+int64(i)*2, 0))
	}

	snap := s.Snapshot(true)
	if len(snap.HostHistory) != 3 {
		t.Fatalf("history length = %d, want 3", len(snap.HostHistory))
	}
	for i := 1; i < len(snap.HostHistory); i++ {
		if snap.HostHistory[i].T <= snap.HostHistory[i-1].T {
			t.Errorf("history is not oldest first: %+v", snap.HostHistory)
		}
	}
	if snap.HostHistory[2].T != time.Unix(1010, 0).UnixMilli() {
		t.Errorf("newest = %d", snap.HostHistory[2].T)
	}
	if without := s.Snapshot(false); without.HostHistory != nil {
		t.Error("history must only be sent when asked for")
	}
}

func rawStats(name string, at time.Time, cpu, sys uint64, rx, tx uint64) docker.RawStats {
	return docker.RawStats{Name: name, At: at, CPUTotal: cpu, SystemCPU: sys, OnlineCPUs: 4, MemUsed: 100 << 20, MemLimit: 512 << 20, NetRx: rx, NetTx: tx}
}

func TestContainerRatesAndVanishedContainers(t *testing.T) {
	s := New(Config{ProcDir: t.TempDir(), DataDir: t.TempDir()})
	t0 := time.Unix(1000, 0)

	s.sampleContainers([]docker.RawStats{rawStats("shop-1", t0, 1_000_000_000, 40_000_000_000, 1000, 100), rawStats("api-3", t0, 5, 5, 0, 0)})
	if got := s.Snapshot(false).Containers; len(got) != 0 {
		t.Fatalf("first readings must not produce rates: %+v", got)
	}

	// 0.5s of CPU time while the 4 cores had 8s between them: 0.5/8*4 = 25% of one core.
	s.sampleContainers([]docker.RawStats{rawStats("shop-1", t0.Add(2*time.Second), 1_500_000_000, 48_000_000_000, 5000, 300), rawStats("api-3", t0.Add(2*time.Second), 5, 5, 0, 0)})
	got := s.Snapshot(true).Containers
	if len(got) != 2 || got[0].Name != "api-3" || got[1].Name != "shop-1" {
		t.Fatalf("containers = %+v", got)
	}
	shop := got[1]
	if shop.CPUPercent != 25 || shop.NetRxBps != 2000 || shop.NetTxBps != 100 || shop.MemUsed != 100<<20 || shop.MemLimit != 512<<20 {
		t.Errorf("shop-1 = %+v", shop)
	}
	if len(shop.History) != 1 || shop.History[0].CPU != 25 {
		t.Errorf("history = %+v", shop.History)
	}

	// api-3 is gone; shop-1 stays.
	s.sampleContainers([]docker.RawStats{rawStats("shop-1", t0.Add(4*time.Second), 1_500_000_000, 56_000_000_000, 5000, 300)})
	if got := s.Snapshot(false).Containers; len(got) != 1 || got[0].Name != "shop-1" {
		t.Errorf("after api-3 left: %+v", got)
	}
}

type fakeSource struct {
	calls int
	stats []docker.RawStats
}

func (f *fakeSource) Stats(context.Context) ([]docker.RawStats, error) {
	f.calls++
	return f.stats, nil
}

func TestContainersAreOnlySampledWhileSomebodyIsLooking(t *testing.T) {
	now := time.Unix(1000, 0)
	src := &fakeSource{}
	s := New(Config{ProcDir: t.TempDir(), DataDir: t.TempDir(), Containers: src, Now: func() time.Time { return now }})

	if s.wanted() {
		t.Fatal("nobody has asked yet")
	}
	s.Snapshot(false)
	if !s.wanted() {
		t.Fatal("a snapshot must switch container sampling on")
	}

	now = now.Add(wantedFor + time.Second)
	if s.wanted() {
		t.Error("sampling must stop once nobody has asked for a minute")
	}

	// Stale readings are dropped so the next visitor does not see old rates.
	s.sampleContainers([]docker.RawStats{rawStats("shop-1", now, 1, 1, 0, 0)})
	s.sampleContainers([]docker.RawStats{rawStats("shop-1", now.Add(2*time.Second), 2, 2, 0, 0)})
	if len(s.Snapshot(false).Containers) != 1 {
		t.Fatal("expected a container before forgetting")
	}
	now = now.Add(wantedFor * 2)
	s.forgetContainers()
	if len(s.Snapshot(false).Containers) != 0 {
		t.Error("containers must be forgotten when nobody is looking")
	}
}

func TestRunStopsWhenTheContextEnds(t *testing.T) {
	dir := t.TempDir()
	writeProc(t, dir, stat(1, 1, 0), netDev(0, 0, 0), meminfo)
	s := New(Config{ProcDir: dir, DataDir: dir, Interval: 5 * time.Millisecond, Containers: &fakeSource{}})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { s.Run(ctx); close(done) }()
	time.Sleep(30 * time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run did not stop")
	}
	if !s.Snapshot(false).Host.Ready {
		t.Error("expected the host to have been sampled")
	}
}
