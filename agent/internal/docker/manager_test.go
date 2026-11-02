package docker

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"commypanel/agent/internal/validate"
)

// fakeDaemon is a tiny stand-in for the Docker Engine API.
type fakeDaemon struct {
	mu              sync.Mutex
	requests        []string
	created         map[string]any
	labels          map[string]string // labels returned by inspect; nil = container missing
	buildTar        []string
	buildReply      string
	waitCode        int
	mounts          []string // sources mounted by the container the list reports
	execStatus      int
	execExit        int
	image           string // image the inspect call reports; empty = an official database image
	execRunning     bool   // the exec still reports running when asked for its result
	execStderr      string
	execReply       string
	stdin           string
	imageMissing    bool
	pullReply       string
	pulled          string
	connected       []string
	connectBody     map[string]any
	execBody        map[string]any
	resized         string
	netMissing      bool // the per-app network does not exist yet
	netForeign      bool // it exists but was not created by the agent
	netDeleteStatus int
	upgrade         string
}

func (f *fakeDaemon) handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// An exec turns into a stream, so it must not hold the lock.
		if strings.HasPrefix(r.URL.Path, "/exec/") && strings.HasSuffix(r.URL.Path, "/start") {
			var start map[string]any
			_ = json.NewDecoder(r.Body).Decode(&start)
			f.mu.Lock()
			f.requests = append(f.requests, r.Method+" "+r.URL.Path)
			f.upgrade = r.Header.Get("Upgrade")
			f.mu.Unlock()
			conn, _, err := w.(http.Hijacker).Hijack()
			if err != nil {
				return
			}
			defer conn.Close()
			conn.Write([]byte("HTTP/1.1 101 UPGRADED\r\nConnection: Upgrade\r\nUpgrade: tcp\r\n\r\n"))
			if start["Tty"] != true {
				// Without a terminal: read the input to its end (only when the exec attached stdin, as
				// Docker does), and answer in the multiplexed format.
				f.mu.Lock()
				attached := f.execBody["AttachStdin"] == true
				f.mu.Unlock()
				var input []byte
				if attached {
					input, _ = io.ReadAll(conn)
				}
				f.mu.Lock()
				f.stdin = string(input)
				reply := f.execReply
				f.mu.Unlock()
				if reply == "" {
					reply = "OUT:" + string(input)
				}
				frame := make([]byte, 8, 8+len(reply))
				frame[0] = 1
				binary.BigEndian.PutUint32(frame[4:], uint32(len(reply)))
				conn.Write(append(frame, reply...))
				f.mu.Lock()
				messages := f.execStderr
				f.mu.Unlock()
				if messages != "" {
					errFrame := make([]byte, 8, 8+len(messages))
					errFrame[0] = 2
					binary.BigEndian.PutUint32(errFrame[4:], uint32(len(messages)))
					conn.Write(append(errFrame, messages...))
				}
				return
			}
			buf := make([]byte, 256)
			for {
				n, err := conn.Read(buf)
				if err != nil {
					return
				}
				conn.Write(append([]byte("echo:"), buf[:n]...))
			}
		}
		f.mu.Lock()
		defer f.mu.Unlock()
		f.requests = append(f.requests, r.Method+" "+r.URL.Path)
		switch {
		case r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/exec/") && strings.HasSuffix(r.URL.Path, "/json"):
			_ = json.NewEncoder(w).Encode(map[string]any{"ExitCode": f.execExit, "Running": f.execRunning})
		case r.Method == "GET" && r.URL.Path != "/images/json" && strings.HasPrefix(r.URL.Path, "/images/") && strings.HasSuffix(r.URL.Path, "/json"):
			if f.imageMissing {
				w.WriteHeader(404)
				w.Write([]byte(`{"message":"no such image"}`))
				return
			}
			w.Write([]byte(`{}`))
		case r.Method == "POST" && r.URL.Path == "/images/create":
			f.pulled = r.URL.RawQuery
			w.Write([]byte(f.pullReply))
		case r.Method == "POST" && strings.HasPrefix(r.URL.Path, "/networks/") && strings.HasSuffix(r.URL.Path, "/connect"):
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			f.connected = append(f.connected, strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/networks/"), "/connect"))
			f.connectBody = body
			w.WriteHeader(200)
		case r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/exec"):
			if f.execStatus != 0 {
				w.WriteHeader(f.execStatus)
				w.Write([]byte(`{"message":"container is not running"}`))
				return
			}
			_ = json.NewDecoder(r.Body).Decode(&f.execBody)
			w.WriteHeader(201)
			w.Write([]byte(`{"Id":"exec1"}`))
		case r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/resize"):
			f.resized = r.URL.RawQuery
			w.WriteHeader(200)
		case r.Method == "GET" && r.URL.Path == "/networks":
			w.Write([]byte(`[{"Id":"n1"},{"Id":"gone"}]`))
		case r.Method == "GET" && r.URL.Path == "/networks/gone":
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"no such network"}`))
		case r.Method == "GET" && r.URL.Path == "/networks/n1":
			w.Write([]byte(`{"Name":"commypanel","Driver":"bridge","Internal":false,
				"IPAM":{"Config":[{"Subnet":"172.20.0.0/16","Gateway":"172.20.0.1"}]},
				"Containers":{"b":{"Name":"shop-2","IPv4Address":"172.20.0.3/16"},"a":{"Name":"shop-1","IPv4Address":"172.20.0.2/16"}}}`))
		case r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/networks/commypanel-"):
			if f.netMissing {
				w.WriteHeader(404)
				w.Write([]byte(`{"message":"no such network"}`))
				return
			}
			labels := map[string]string{ManagedLabel: "true"}
			if f.netForeign {
				labels = map[string]string{"other": "x"}
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"Labels": labels})
		case r.Method == "DELETE" && strings.HasPrefix(r.URL.Path, "/networks/"):
			if f.netDeleteStatus != 0 {
				w.WriteHeader(f.netDeleteStatus)
				w.Write([]byte(`{"message":"network has active endpoints"}`))
				return
			}
			w.WriteHeader(204)
		case r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/networks/"):
			w.WriteHeader(200)
			w.Write([]byte(`{}`))
		case r.URL.Path == "/containers/create":
			_ = json.NewDecoder(r.Body).Decode(&f.created)
			w.WriteHeader(201)
			w.Write([]byte(`{"Id":"abc"}`))
		case strings.HasSuffix(r.URL.Path, "/json") && r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/containers/") && r.URL.Path != "/containers/json":
			if f.labels == nil {
				w.WriteHeader(404)
				w.Write([]byte(`{"message":"no such container"}`))
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"Config": map[string]any{"Labels": f.labels, "Image": f.databaseImage()}})
		case r.URL.Path == "/containers/json":
			mounts := []map[string]any{}
			for _, source := range f.mounts {
				mounts = append(mounts, map[string]any{"Source": source, "Destination": "/data", "RW": true})
			}
			// A mount outside the volumes directory is not named.
			mounts = append(mounts, map[string]any{"Source": "/etc/secret", "Destination": "/x", "RW": false})
			_ = json.NewEncoder(w).Encode([]map[string]any{{
				"Names": []string{"/demo-1"}, "Image": "demo:1", "State": "running", "Status": "Up 2 minutes", "Mounts": mounts,
				"Ports": []map[string]any{
					{"IP": "127.0.0.1", "PrivatePort": 8080, "PublicPort": 20001, "Type": "tcp"},
					{"PrivatePort": 9090, "Type": "tcp"}, // exposed but not published
				},
				"NetworkSettings": map[string]any{"Networks": map[string]any{
					"commypanel": map[string]string{"IPAddress": "172.20.0.2"},
					"bridge":     map[string]string{"IPAddress": "172.17.0.2"},
				}},
			}})
		case strings.HasSuffix(r.URL.Path, "/stats"):
			if r.URL.Query().Get("stream") != "false" || r.URL.Query().Get("one-shot") != "true" {
				w.WriteHeader(400)
				return
			}
			w.Write([]byte(`{"cpu_stats":{"cpu_usage":{"total_usage":2000},"system_cpu_usage":50000,"online_cpus":4},
				"memory_stats":{"usage":300,"limit":1000,"stats":{"inactive_file":100,"active_file":7}},
				"networks":{"eth0":{"rx_bytes":10,"tx_bytes":4},"eth1":{"rx_bytes":5,"tx_bytes":1}}}`))
		case strings.HasSuffix(r.URL.Path, "/wait"):
			_ = json.NewEncoder(w).Encode(map[string]int{"StatusCode": f.waitCode})
		case strings.HasSuffix(r.URL.Path, "/logs"):
			var buf bytes.Buffer
			for _, line := range []string{"hello\n", "world\n"} {
				h := make([]byte, 8)
				h[0] = 1
				binary.BigEndian.PutUint32(h[4:], uint32(len(line)))
				buf.Write(h)
				buf.WriteString(line)
			}
			w.Write(buf.Bytes())
		case r.URL.Path == "/build":
			tr := tar.NewReader(r.Body)
			for {
				hdr, err := tr.Next()
				if err != nil {
					break
				}
				f.buildTar = append(f.buildTar, hdr.Name)
			}
			w.Write([]byte(f.buildReply))
		case r.URL.Path == "/version":
			w.Write([]byte(`{"Version":"27.1.0","ApiVersion":"1.46","Os":"linux","Arch":"amd64","KernelVersion":"6.8.0"}`))
		case r.URL.Path == "/info":
			w.Write([]byte(`{"Containers":5,"ContainersRunning":3,"ContainersPaused":0,"ContainersStopped":2,"Images":7,"Driver":"overlay2","CgroupDriver":"systemd","NCPU":4,"MemTotal":4294967296}`))
		case r.URL.Path == "/images/json":
			w.Write([]byte(`[{"RepoTags":["commypanel/old:1","other:latest"],"Size":100,"Created":10},{"RepoTags":["commypanel/new:2"],"Size":200,"Created":20},{"RepoTags":["nginx:latest"],"Size":1,"Created":30}]`))
		case r.URL.Path == "/images/prune":
			w.Write([]byte(`{"SpaceReclaimed":1234}`))
		default:
			w.WriteHeader(204)
		}
	})
}

func (f *fakeDaemon) has(req string) bool {
	for _, r := range f.requests {
		if r == req {
			return true
		}
	}
	return false
}

func setup(t *testing.T) (*Manager, *fakeDaemon, string) {
	t.Helper()
	d := &fakeDaemon{labels: map[string]string{ManagedLabel: "true"}}
	srv := httptest.NewServer(d.handler())
	t.Cleanup(srv.Close)
	dir := t.TempDir()
	m := NewManager(Config{DataDir: dir}, NewEngine(srv.URL, nil))
	return m, d, dir
}

func baseSpec() ContainerSpec {
	return ContainerSpec{Name: "demo-1", Image: "commypanel/demo:r1", HostPort: 20001, ContainerPort: 8080}
}

func TestRunHardcodesSecuritySettings(t *testing.T) {
	m, d, dir := setup(t)
	spec := baseSpec()
	spec.Env = map[string]string{"A": "1"}
	spec.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "demo", "data"), Target: "/data"}}

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}

	host := d.created["HostConfig"].(map[string]any)
	if host["Privileged"] != false {
		t.Error("container must not be privileged")
	}
	if host["NetworkMode"] != "commypanel" {
		t.Errorf("network = %v", host["NetworkMode"])
	}
	if host["Memory"].(float64) != 512<<20 {
		t.Errorf("default memory limit not applied: %v", host["Memory"])
	}
	if host["NanoCpus"].(float64) != 1e9 {
		t.Errorf("default cpu limit not applied: %v", host["NanoCpus"])
	}
	if opts := host["SecurityOpt"].([]any); len(opts) != 1 || opts[0] != "no-new-privileges:true" {
		t.Errorf("security opts = %v", opts)
	}
	binding := host["PortBindings"].(map[string]any)["8080/tcp"].([]any)[0].(map[string]any)
	if binding["HostIp"] != "127.0.0.1" || binding["HostPort"] != "20001" {
		t.Errorf("port must be bound to localhost only: %v", binding)
	}
	if d.created["Labels"].(map[string]any)[ManagedLabel] != "true" {
		t.Error("managed label missing")
	}
	if !d.has("POST /containers/demo-1/start") {
		t.Error("container not started")
	}
	if _, err := os.Stat(filepath.Join(dir, "volumes", "demo", "data")); err != nil {
		t.Errorf("volume dir not created: %v", err)
	}
}

func TestRunRejectsDangerousSpecs(t *testing.T) {
	m, d, dir := setup(t)
	bad := map[string]func(*ContainerSpec){
		"bad name":               func(s *ContainerSpec) { s.Name = "Demo;rm" },
		"bad image":              func(s *ContainerSpec) { s.Image = "img --privileged" },
		"privileged port":        func(s *ContainerSpec) { s.HostPort = 80 },
		"missing container port": func(s *ContainerSpec) { s.ContainerPort = 0 },
		"env key injection":      func(s *ContainerSpec) { s.Env = map[string]string{"A=B": "x"} },
		"env nul":                func(s *ContainerSpec) { s.Env = map[string]string{"A": "x\x00y"} },
		"docker socket mount": func(s *ContainerSpec) {
			s.Volumes = []Volume{{Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"}}
		},
		"host root mount": func(s *ContainerSpec) { s.Volumes = []Volume{{Source: "/", Target: "/host"}} },
		"traversal mount": func(s *ContainerSpec) {
			s.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "..", "..", "etc"), Target: "/x"}}
		},
		"bad target": func(s *ContainerSpec) {
			s.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "a"), Target: "/a:/b"}}
		},
		"tiny memory": func(s *ContainerSpec) { s.MemoryMB = 1 },
		"bad network": func(s *ContainerSpec) { s.Network = "host;x" },
	}
	for name, mutate := range bad {
		spec := baseSpec()
		mutate(&spec)
		err := m.Run(context.Background(), spec)
		var ie *validate.InputError
		if !errors.As(err, &ie) {
			t.Errorf("%s: want *InputError, got %v", name, err)
		}
	}
	if d.has("POST /containers/create") {
		t.Error("nothing invalid may reach Docker")
	}
}

func TestStopAndRemoveOnlyTouchManagedContainers(t *testing.T) {
	m, d, _ := setup(t)
	d.labels = map[string]string{"other": "x"}

	for name, fn := range map[string]func() error{
		"stop":   func() error { return m.Stop(context.Background(), "someone-elses") },
		"remove": func() error { return m.Remove(context.Background(), "someone-elses") },
	} {
		var ie *validate.InputError
		if err := fn(); !errors.As(err, &ie) {
			t.Errorf("%s: want *InputError for an unmanaged container, got %v", name, err)
		}
	}
	if d.has("POST /containers/someone-elses/stop") || d.has("DELETE /containers/someone-elses") {
		t.Error("an unmanaged container must never be stopped or removed")
	}
}

func TestStopAndRemoveAreIdempotent(t *testing.T) {
	m, d, _ := setup(t)
	d.labels = nil // container does not exist
	if err := m.Stop(context.Background(), "gone"); err != nil {
		t.Errorf("stop missing: %v", err)
	}
	if err := m.Remove(context.Background(), "gone"); err != nil {
		t.Errorf("remove missing: %v", err)
	}
}

func TestRemoveForceRemovesManagedContainer(t *testing.T) {
	m, d, _ := setup(t)
	if err := m.Remove(context.Background(), "demo-1"); err != nil {
		t.Fatal(err)
	}
	if !d.has("DELETE /containers/demo-1") {
		t.Error("expected DELETE")
	}
}

func TestListAndLogsAndPrune(t *testing.T) {
	m, _, _ := setup(t)
	list, err := m.List(context.Background())
	if err != nil || len(list) != 1 || list[0].Name != "demo-1" || list[0].State != "running" {
		t.Errorf("list = %v, %v", list, err)
	}
	logs, err := m.Logs(context.Background(), "demo-1", 100)
	if err != nil || logs != "hello\nworld\n" {
		t.Errorf("logs = %q, %v", logs, err)
	}
	if _, err := m.Logs(context.Background(), "demo-1", 0); err == nil {
		t.Error("tail=0 must be rejected")
	}
	n, err := m.PruneImages(context.Background())
	if err != nil || n != 1234 {
		t.Errorf("prune = %d, %v", n, err)
	}
}

func TestUnavailableDaemon(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	srv.Close() // nothing listens any more
	m := NewManager(Config{DataDir: t.TempDir()}, NewEngine(srv.URL, nil))

	err := m.Run(context.Background(), baseSpec())
	if !errors.Is(err, ErrUnavailable) {
		t.Errorf("want ErrUnavailable, got %v", err)
	}
}

func TestDryRunNeverCallsDocker(t *testing.T) {
	d := &fakeDaemon{}
	srv := httptest.NewServer(d.handler())
	defer srv.Close()
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, NewEngine(srv.URL, nil))

	ctx := context.Background()
	if err := m.Run(ctx, baseSpec()); err != nil {
		t.Error(err)
	}
	if err := m.Stop(ctx, "demo-1"); err != nil {
		t.Error(err)
	}
	if err := m.Remove(ctx, "demo-1"); err != nil {
		t.Error(err)
	}
	if _, err := m.List(ctx); err != nil {
		t.Error(err)
	}
	bad := baseSpec()
	bad.Image = "bad image"
	if err := m.Run(ctx, bad); err == nil {
		t.Error("dry run must still validate")
	}
	if len(d.requests) != 0 {
		t.Errorf("dry run called Docker: %v", d.requests)
	}
}

func buildContext(t *testing.T, dir string) string {
	t.Helper()
	ctxDir := filepath.Join(dir, "builds", "demo-1")
	if err := os.MkdirAll(filepath.Join(ctxDir, "src"), 0o755); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(filepath.Join(ctxDir, "Dockerfile"), []byte("FROM scratch\n"), 0o644)
	os.WriteFile(filepath.Join(ctxDir, "src", "main.go"), []byte("package main\n"), 0o644)
	os.Symlink("/etc/passwd", filepath.Join(ctxDir, "link"))
	return ctxDir
}

func TestBuildStreamsContextAndLogs(t *testing.T) {
	m, d, dir := setup(t)
	d.buildReply = `{"stream":"Step 1/1 : FROM scratch\n"}` + "\n" + `{"aux":{"ID":"sha256:x"}}` + "\n" + `{"stream":"Successfully built\n"}` + "\n"
	ctxDir := buildContext(t, dir)

	var lines []string
	err := m.Build(context.Background(), BuildSpec{Tag: "commypanel/demo:r1", ContextPath: ctxDir}, func(l string) { lines = append(lines, l) })
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(lines, "|"); got != "Step 1/1 : FROM scratch|Successfully built" {
		t.Errorf("log lines = %q", got)
	}
	tarred := strings.Join(d.buildTar, ",")
	for _, want := range []string{"Dockerfile", "src/", "src/main.go", "link"} {
		if !strings.Contains(tarred, want) {
			t.Errorf("tar missing %q: %s", want, tarred)
		}
	}
}

func TestBuildReportsFailure(t *testing.T) {
	m, d, dir := setup(t)
	d.buildReply = `{"stream":"Step 1\n"}` + "\n" + `{"error":"The command failed","errorDetail":{"message":"x"}}` + "\n"
	err := m.Build(context.Background(), BuildSpec{Tag: "commypanel/demo:r1", ContextPath: buildContext(t, dir)}, func(string) {})
	if err == nil || !strings.Contains(err.Error(), "The command failed") {
		t.Errorf("err = %v", err)
	}
}

func TestBuildRejectsBadSpecs(t *testing.T) {
	m, d, dir := setup(t)
	ctxDir := buildContext(t, dir)
	for name, spec := range map[string]BuildSpec{
		"foreign tag":         {Tag: "library/alpine:3", ContextPath: ctxDir},
		"bad tag":             {Tag: "commypanel/Demo;x", ContextPath: ctxDir},
		"context outside":     {Tag: "commypanel/demo:r1", ContextPath: "/etc"},
		"context traversal":   {Tag: "commypanel/demo:r1", ContextPath: ctxDir + "/../../.."},
		"dockerfile escape":   {Tag: "commypanel/demo:r1", ContextPath: ctxDir, Dockerfile: "../../etc/passwd"},
		"absolute dockerfile": {Tag: "commypanel/demo:r1", ContextPath: ctxDir, Dockerfile: "/etc/passwd"},
	} {
		if err := m.Build(context.Background(), spec, func(string) {}); err == nil {
			t.Errorf("%s: want error", name)
		}
	}
	if d.has("POST /build") {
		t.Error("an invalid build must not reach Docker")
	}
}

func TestBuildNeedsADockerfile(t *testing.T) {
	m, _, dir := setup(t)
	empty := filepath.Join(dir, "builds", "empty")
	os.MkdirAll(empty, 0o755)
	err := m.Build(context.Background(), BuildSpec{Tag: "commypanel/demo:r1", ContextPath: empty}, func(string) {})
	var ie *validate.InputError
	if !errors.As(err, &ie) {
		t.Errorf("want *InputError, got %v", err)
	}
}

func TestRemoveImage(t *testing.T) {
	m, d, _ := setup(t)
	if err := m.RemoveImage(context.Background(), "commypanel/demo:1"); err != nil {
		t.Fatal(err)
	}
	if !d.has("DELETE /images/commypanel/demo:1") {
		t.Errorf("requests = %v", d.requests)
	}
	for _, tag := range []string{"library/alpine:3", "commypanel/Bad Tag", "mariadb"} {
		if err := m.RemoveImage(context.Background(), tag); err == nil {
			t.Errorf("%q must be rejected", tag)
		}
	}
}

func TestInfoSummarisesTheDaemon(t *testing.T) {
	m, _, _ := setup(t)

	info, err := m.Info(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if !info.Available || info.ServerVersion != "27.1.0" || info.StorageDriver != "overlay2" || info.Running != 3 || info.Images != 7 || info.CPUs != 4 {
		t.Errorf("info = %+v", info)
	}
}

func TestInfoReportsAnUnreachableDaemonInsteadOfFailing(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir()}, NewEngine("http://127.0.0.1:1", nil))

	info, err := m.Info(context.Background())

	if err != nil {
		t.Fatalf("an unreachable daemon is a state, not an error: %v", err)
	}
	if info.Available || info.Error == "" {
		t.Errorf("info = %+v", info)
	}
}

func TestListImagesKeepsOnlyOurNamespaceNewestFirst(t *testing.T) {
	m, _, _ := setup(t)

	images, err := m.ListImages(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if len(images) != 2 || images[0].Tags[0] != "commypanel/new:2" || images[1].Tags[0] != "commypanel/old:1" {
		t.Fatalf("images = %+v", images)
	}
	if len(images[1].Tags) != 1 {
		t.Errorf("foreign tags must be dropped: %+v", images[1].Tags)
	}
}

func logFrame(stream byte, text string) []byte {
	frame := make([]byte, 8, 8+len(text))
	frame[0] = stream
	binary.BigEndian.PutUint32(frame[4:], uint32(len(text)))
	return append(frame, text...)
}

func TestDemuxJoinsBothStreams(t *testing.T) {
	var in bytes.Buffer
	in.Write(logFrame(1, "out line\n"))
	in.Write(logFrame(2, "err line\n"))

	if got := demux(&in); got != "out line\nerr line\n" {
		t.Fatalf("got %q", got)
	}
}

func TestDemuxKeepsTheNewestLinesWhenTooLarge(t *testing.T) {
	var in bytes.Buffer
	line := strings.Repeat("x", 99) + "\n" // 100 bytes
	total := maxLogBytes/len(line) + 500
	for i := 0; i < total; i++ {
		in.Write(logFrame(1, line))
	}
	in.Write(logFrame(1, "the newest line\n"))

	got := demux(&in)
	if len(got) > maxLogBytes {
		t.Fatalf("kept %d bytes, limit %d", len(got), maxLogBytes)
	}
	if !strings.HasSuffix(got, "the newest line\n") {
		t.Fatal("the newest line was cut off")
	}
	if !strings.HasPrefix(got, "x") || strings.Index(got, "\n") != 99 {
		t.Fatal("output does not start on a whole line")
	}
}

// databaseImage is the image the fake reports for any container it knows: a
// database one, unless a test says otherwise.
func (f *fakeDaemon) databaseImage() string {
	if f.image != "" {
		return f.image
	}
	return "mariadb:11.4"
}

func TestAnAppCannotMountADatabaseServersFilesAndTheOtherWayRound(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir()}, nil)
	dbData := m.databasesDir() + "/main"
	appData := m.volumesDir() + "/shop/data"

	app := ContainerSpec{Name: "shop-1", Image: "commypanel/shop:r1", Volumes: []Volume{{Source: dbData, Target: "/x"}}}
	if err := m.Validate(app); err == nil {
		t.Error("an app image was allowed to mount a database server's data")
	}
	app.Volumes = []Volume{{Source: appData, Target: "/x"}}
	if err := m.Validate(app); err != nil {
		t.Errorf("an app's own volume must be allowed: %v", err)
	}

	db := ContainerSpec{Name: "db-main", Image: "postgres:17", Volumes: []Volume{{Source: appData, Target: "/var/lib/postgresql/data"}}}
	if err := m.Validate(db); err == nil {
		t.Error("a database image was allowed to mount an app's volume")
	}
	db.Volumes = []Volume{{Source: dbData, Target: "/var/lib/postgresql/data"}}
	if err := m.Validate(db); err != nil {
		t.Errorf("a database server's own data folder must be allowed: %v", err)
	}
}
