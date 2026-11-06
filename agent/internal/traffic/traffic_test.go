package traffic

import (
	"context"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"testing"
	"time"
)

type clock struct{ t time.Time }

func (c *clock) now() time.Time { return c.t }

func newStore() (*Store, *clock) {
	c := &clock{t: time.Unix(1_800_000_000, 0)}
	return New(c.now), c
}

func TestParseLineReadsWhatNginxSends(t *testing.T) {
	cases := map[string]struct {
		line string
		want Request
		ok   bool
	}{
		"plain":                             {"shop|200|0.012|127.0.0.1:20003|200|0.011", Request{"shop", 200, 20003, 0.012}, true},
		"with syslog head":                  {"<190>Nov  6 12:00:00 nginx: shop|502|0.003|127.0.0.1:20003|502|0.002\n", Request{"shop", 502, 20003, 0.003}, true},
		"a retry counts the last container": {"shop|200|0.5|127.0.0.1:20001, 127.0.0.1:20002|502, 200|0.1, 0.2", Request{"shop", 200, 20002, 0.5}, true},
		"no upstream":                       {"shop|301|0.000|-|-|-", Request{}, false},
		"a unix socket":                     {"shop|200|0.1|unix:/run/x.sock|200|0.1", Request{}, false},
		"another host":                      {"shop|200|0.1|10.0.0.5:8080|200|0.1", Request{}, false},
		"low port":                          {"shop|200|0.1|127.0.0.1:80|200|0.1", Request{"shop", 200, 80, 0.1}, true}, // parsed; Record refuses it
		"bad status":                        {"shop|abc|0.1|127.0.0.1:20003|200|0.1", Request{}, false},
		"bad time":                          {"shop|200|x|127.0.0.1:20003|200|0.1", Request{}, false},
		"negative time":                     {"shop|200|-1|127.0.0.1:20003|200|0.1", Request{}, false},
		"too few fields":                    {"shop|200|0.1", Request{}, false},
		"too many fields":                   {"shop|200|0.1|127.0.0.1:20003|200|0.1|extra", Request{}, false},
		"a bad site name":                   {"Shop|200|0.1|127.0.0.1:20003|200|0.1", Request{}, false},
		"a site with junk":                  {"sh op|200|0.1|127.0.0.1:20003|200|0.1", Request{}, false},
		"empty":                             {"", Request{}, false},
	}
	for name, c := range cases {
		got, ok := ParseLine([]byte(c.line))
		if ok != c.ok || (ok && got != c.want) {
			t.Errorf("%s: got %+v %v, want %+v %v", name, got, ok, c.want, c.ok)
		}
	}
}

func TestOversizedLinesAreIgnored(t *testing.T) {
	long := make([]byte, maxLine+1)
	for i := range long {
		long[i] = 'a'
	}
	if _, ok := ParseLine(long); ok {
		t.Error("a huge datagram was parsed")
	}
}

func TestRecordCountsRequestsErrorsAndLatencyPerPort(t *testing.T) {
	s, c := newStore()
	for i := 0; i < 100; i++ {
		s.Record(Request{"shop", 200, 20001, 0.010})
	}
	for i := 0; i < 5; i++ {
		s.Record(Request{"shop", 500, 20001, 0.300})
	}
	s.Record(Request{"shop", 404, 20001, 0.001}) // a client error is not a failure
	s.Record(Request{"shop", 200, 20002, 0.050})
	s.Record(Request{"blog", 200, 20001, 0.050})

	now := c.t.Unix()
	got := s.Range("shop", Query{From: now - 60, To: now + 60})
	if len(got) != 2 {
		t.Fatalf("ports = %v", got)
	}
	old := got[20001]
	if old.Requests != 106 || old.Errors != 5 {
		t.Errorf("old = %+v", old)
	}
	if old.P50 != 10 || old.P95 != 10 || old.P99 != 500 {
		t.Errorf("percentiles p50=%v p95=%v p99=%v", old.P50, old.P95, old.P99)
	}
	if got[20002].Requests != 1 || got[20002].Errors != 0 {
		t.Errorf("new = %+v", got[20002])
	}
	if len(s.Range("blog", Query{From: now - 60, To: now + 60})) != 1 {
		t.Error("another site leaked in")
	}
}

func TestRecordIgnoresWhatIsNotARealRequestToAContainer(t *testing.T) {
	s, c := newStore()
	for _, r := range []Request{{"shop", 200, 80, 0.1}, {"shop", 200, 70000, 0.1}, {"shop", 99, 20001, 0.1}, {"shop", 600, 20001, 0.1}} {
		s.Record(r)
	}
	if n := len(s.Range("shop", Query{From: c.t.Unix() - 60, To: c.t.Unix() + 60})); n != 0 {
		t.Errorf("counted %d ports", n)
	}
}

func TestARangeOnlyCountsItsOwnTimeAndOldBucketsAreReplaced(t *testing.T) {
	s, c := newStore()
	start := c.t.Unix()
	s.Record(Request{"shop", 200, 20001, 0.01})
	c.t = c.t.Add(30 * time.Minute)
	s.Record(Request{"shop", 500, 20001, 0.01})

	first := s.Range("shop", Query{From: start - 10, To: start + 10})[20001]
	last := s.Range("shop", Query{From: c.t.Unix() - 10, To: c.t.Unix() + 10})[20001]
	if first == nil || first.Requests != 1 || first.Errors != 0 || last == nil || last.Requests != 1 || last.Errors != 1 {
		t.Fatalf("first %+v last %+v", first, last)
	}
	// Past an hour the ring has wrapped over the first request.
	c.t = c.t.Add(31 * time.Minute)
	s.Record(Request{"shop", 200, 20001, 0.01})
	all := s.Range("shop", Query{From: start - 10, To: c.t.Unix() + 10})[20001]
	if all.Requests != 2 { // the 30-minute one and the new one; the first aged out
		t.Errorf("requests = %d", all.Requests)
	}
}

func TestASeriesFillsEveryStepIncludingQuietOnes(t *testing.T) {
	s, c := newStore()
	start := c.t.Unix() - c.t.Unix()%BucketSeconds
	s.Record(Request{"shop", 200, 20001, 0.01})
	c.t = c.t.Add(40 * time.Second)
	s.Record(Request{"shop", 500, 20001, 0.01})

	st := s.Range("shop", Query{From: start, To: start + 60, Step: 20})[20001]
	if len(st.Series) != 3 {
		t.Fatalf("series = %+v", st.Series)
	}
	if st.Series[0].Requests != 1 || st.Series[1].Requests != 0 || st.Series[2].Requests != 1 || st.Series[2].Errors != 1 {
		t.Errorf("series = %+v", st.Series)
	}
}

func TestAnInvertedRangeIsEmpty(t *testing.T) {
	s, c := newStore()
	s.Record(Request{"shop", 200, 20001, 0.01})
	if n := len(s.Range("shop", Query{From: c.t.Unix() + 10, To: c.t.Unix()})); n != 0 {
		t.Errorf("%d ports", n)
	}
}

func TestTheStoreIsBoundedAndKeepsWorkingWhenFull(t *testing.T) {
	s, c := newStore()
	for i := 0; i < maxKeys; i++ {
		s.Record(Request{"s" + strconv.Itoa(i), 200, 20001, 0.01})
	}
	s.Record(Request{"overflow", 200, 20001, 0.01})
	if s.Dropped() != 1 {
		t.Errorf("dropped = %d", s.Dropped())
	}
	// Once the quiet ones are an hour old they make room.
	c.t = c.t.Add(2 * time.Hour)
	s.Record(Request{"overflow", 200, 20001, 0.01})
	if n := len(s.Range("overflow", Query{From: c.t.Unix() - 20, To: c.t.Unix() + 20})); n != 1 {
		t.Errorf("not recorded after the quiet ones aged out: %d", n)
	}
}

func TestConcurrentWritersAndReadersAreSafe(t *testing.T) {
	s := New(nil)
	var wg sync.WaitGroup
	for g := 0; g < 8; g++ {
		wg.Add(1)
		go func(g int) {
			defer wg.Done()
			for i := 0; i < 500; i++ {
				s.Record(Request{"shop", 200 + (i%3)*150, 20001 + g%2, 0.01})
				if i%50 == 0 {
					now := time.Now().Unix()
					s.Range("shop", Query{From: now - 60, To: now + 60, Step: 10})
				}
			}
		}(g)
	}
	wg.Wait()
	now := time.Now().Unix()
	var total uint64
	for _, st := range s.Range("shop", Query{From: now - 60, To: now + 60}) {
		total += st.Requests
	}
	if total != 4000 {
		t.Errorf("total = %d", total)
	}
}

func TestTheSocketReceivesNginxDatagramsAndCountsThem(t *testing.T) {
	dir, err := os.MkdirTemp("/tmp", "cptr")
	if err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(dir)
	path := filepath.Join(dir, "m.sock")
	s := New(nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := s.Listen(ctx, path, "no-such-group-here", true); err != nil {
		t.Fatal(err)
	}

	conn, err := net.Dial("unixgram", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	for _, line := range []string{
		"<190>Nov  6 12:00:00 nginx: shop|200|0.010|127.0.0.1:20003|200|0.009",
		"<190>Nov  6 12:00:00 nginx: shop|500|0.020|127.0.0.1:20003|500|0.019",
		"garbage",
		"<190>Nov  6 12:00:00 nginx: shop|301|0.000|-|-|-",
	} {
		if _, err := conn.Write([]byte(line)); err != nil {
			t.Fatal(err)
		}
	}
	deadline := time.Now().Add(2 * time.Second)
	for {
		now := time.Now().Unix()
		if st := s.Range("shop", Query{From: now - 60, To: now + 60})[20003]; st != nil && st.Requests == 2 {
			if st.Errors != 1 {
				t.Errorf("errors = %d", st.Errors)
			}
			return
		}
		if time.Now().After(deadline) {
			t.Fatal("the datagrams were not counted")
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func FuzzParseLine(f *testing.F) {
	f.Add([]byte("shop|200|0.012|127.0.0.1:20003|200|0.011"))
	f.Add([]byte("<190>Nov  6 12:00:00 nginx: shop|502|0.003|127.0.0.1:20003, 127.0.0.1:20004|502|0.002"))
	f.Add([]byte("|||||"))
	f.Fuzz(func(t *testing.T, b []byte) {
		if r, ok := ParseLine(b); ok {
			if r.Site == "" || r.Duration < 0 {
				t.Fatalf("parsed nonsense: %+v from %q", r, b)
			}
			s := New(nil)
			s.Record(r) // must never panic
		}
	})
}
