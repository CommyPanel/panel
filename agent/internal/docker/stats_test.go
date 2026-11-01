package docker

import (
	"context"
	"testing"
)

func TestStatsReadsOneSamplePerRunningContainer(t *testing.T) {
	m, d, _ := setup(t)

	got, err := m.Stats(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 {
		t.Fatalf("stats = %+v", got)
	}
	s := got[0]
	if s.Name != "demo-1" || s.CPUTotal != 2000 || s.SystemCPU != 50000 || s.OnlineCPUs != 4 {
		t.Errorf("cpu fields = %+v", s)
	}
	// The reclaimable page cache (inactive_file) is not the app's memory.
	if s.MemUsed != 200 || s.MemLimit != 1000 {
		t.Errorf("memory = %d of %d, want 200 of 1000", s.MemUsed, s.MemLimit)
	}
	if s.NetRx != 15 || s.NetTx != 5 {
		t.Errorf("network = %d/%d, want 15/5 summed over interfaces", s.NetRx, s.NetTx)
	}
	if !d.has("GET /containers/demo-1/stats") {
		t.Errorf("requests = %v", d.requests)
	}
}

func TestStatsInDryRunNeverContactsDocker(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, nil)

	if got, err := m.Stats(context.Background()); err != nil || got != nil {
		t.Errorf("got %v, %v", got, err)
	}
}
