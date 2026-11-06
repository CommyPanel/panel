//go:build integration

package nginx

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/cookiejar"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"commypanel/agent/internal/traffic"
)

// Run in a Linux container that has nginx installed (root, port 80 free):
//
//	go test -tags integration ./internal/nginx -run RealNginx -v
//
// It renders the site the agent would write, has the real nginx load it (`nginx -t`), puts
// two stand-in containers behind it, and checks what the panel relies on: every request is
// counted per container, a rollout sends about the asked share, a visitor stays on one
// version through their cookie, and raising the percentage never moves anyone back.
func TestRealNginx(t *testing.T) {
	if _, err := exec.LookPath("nginx"); err != nil {
		t.Skip("nginx is not installed")
	}
	dir, _ := os.MkdirTemp("/tmp", "cpnginx")
	defer os.RemoveAll(dir)
	sites := filepath.Join(dir, "sites")
	_ = os.MkdirAll(sites, 0o755)

	// Two stand-in containers; "/fail" answers 500 on the new one only.
	serve := func(port int, name string) {
		mux := http.NewServeMux()
		mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path == "/fail" && name == "new" {
				http.Error(w, "boom", 500)
				return
			}
			fmt.Fprint(w, name)
		})
		ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
		if err != nil {
			t.Fatal(err)
		}
		go http.Serve(ln, mux)
		t.Cleanup(func() { ln.Close() })
	}
	serve(20001, "old")
	serve(20002, "new")

	store := traffic.New(nil)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	metrics := filepath.Join(dir, "m.sock")
	if err := store.Listen(ctx, metrics, "root", false); err != nil {
		t.Fatal(err)
	}

	m := NewManager(Config{SitesDir: sites, StateDir: filepath.Join(dir, "state"), DryRun: true, MetricsSocket: metrics}, nil)
	conf := fmt.Sprintf(`user root;
worker_processes 1;
pid %[1]s/nginx.pid;
error_log %[1]s/error.log info;
daemon off;
events { worker_connections 256; }
http {
    access_log off;
    client_body_temp_path %[1]s/body; proxy_temp_path %[1]s/proxy; fastcgi_temp_path %[1]s/fcgi; uwsgi_temp_path %[1]s/uwsgi; scgi_temp_path %[1]s/scgi;
    include %[2]s/*.conf;
}
`, dir, sites)
	mainConf := filepath.Join(dir, "nginx.conf")
	_ = os.WriteFile(mainConf, []byte(conf), 0o644)

	write := func(s Site) {
		t.Helper()
		out, err := m.Render(s)
		if err != nil {
			t.Fatal(err)
		}
		_ = os.WriteFile(filepath.Join(sites, "commypanel-shop.conf"), []byte(out), 0o644)
		if b, err := exec.Command("nginx", "-t", "-c", mainConf).CombinedOutput(); err != nil {
			t.Fatalf("nginx refuses the config: %v\n%s\n%s", err, b, out)
		}
	}
	site := Site{Name: "shop", Domains: []string{"shop.example.test"}, UpstreamPort: 20001}
	write(site)

	cmd := exec.Command("nginx", "-c", mainConf)
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = cmd.Process.Kill(); _, _ = cmd.Process.Wait() })
	reload := func() {
		t.Helper()
		if b, err := exec.Command("nginx", "-s", "reload", "-c", mainConf).CombinedOutput(); err != nil {
			t.Fatalf("reload: %v %s", err, b)
		}
		time.Sleep(400 * time.Millisecond)
	}
	for i := 0; i < 50; i++ {
		if c, err := net.Dial("tcp", "127.0.0.1:80"); err == nil {
			c.Close()
			break
		}
		time.Sleep(100 * time.Millisecond)
	}

	get := func(client *http.Client, path string) (string, int, *http.Response) {
		t.Helper()
		req, _ := http.NewRequest("GET", "http://127.0.0.1"+path, nil)
		req.Host = "shop.example.test"
		resp, err := client.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		b, _ := io.ReadAll(resp.Body)
		return string(b), resp.StatusCode, resp
	}
	t.Cleanup(func() {
		if t.Failed() {
			b, _ := os.ReadFile(filepath.Join(dir, "error.log"))
			t.Logf("nginx error log:\n%s", b)
		}
	})
	counts := func() map[int]*traffic.Stats {
		time.Sleep(300 * time.Millisecond) // datagrams are asynchronous
		now := time.Now().Unix()
		return store.Range("shop", traffic.Query{From: now - 120, To: now + 60})
	}

	// 1. A plain site: everything goes to the old container, and every request is counted.
	plain := &http.Client{}
	for i := 0; i < 20; i++ {
		if body, _, _ := get(plain, "/"); body != "old" {
			t.Fatalf("plain site answered %q", body)
		}
	}
	if st := counts()[20001]; st == nil || st.Requests != 20 || st.Errors != 0 {
		t.Fatalf("plain counts = %+v", st)
	}

	// 2. A rollout at 30%: visitors are split, each gets a cookie, and stays put.
	site.Rollout = &Rollout{Port: 20002, Percent: 30}
	write(site)
	reload()
	const visitors = 400
	type visitor struct {
		client  *http.Client
		bucket  string
		version string
	}
	people := make([]*visitor, visitors)
	newCount := 0
	for i := range people {
		jar, _ := cookiejar.New(nil)
		v := &visitor{client: &http.Client{Jar: jar}}
		body, _, resp := get(v.client, "/")
		v.version = body
		for _, c := range resp.Cookies() {
			if c.Name == "cp_rollout" {
				v.bucket = c.Value
				if len(c.Value) != 2 || !c.HttpOnly {
					t.Fatalf("cookie = %+v", c)
				}
			}
		}
		if v.bucket == "" {
			t.Fatal("a first visit got no cookie")
		}
		if v.version == "new" {
			newCount++
		}
		people[i] = v
	}
	if newCount < 80 || newCount > 160 { // 30% of 400 is 120
		t.Errorf("%d of %d visitors got the new release at 30%%", newCount, visitors)
	}
	for _, v := range people[:60] {
		body, _, resp := get(v.client, "/")
		if body != v.version {
			t.Fatalf("a visitor moved from %s to %s", v.version, body)
		}
		for _, c := range resp.Cookies() {
			if c.Name == "cp_rollout" {
				t.Fatalf("a returning visitor was given a new cookie")
			}
		}
	}
	// The bucket decides: below 30 is new, the rest old.
	for _, v := range people {
		want := "old"
		if v.bucket < "30" {
			want = "new"
		}
		if v.version != want {
			t.Fatalf("bucket %s got %s at 30%%", v.bucket, v.version)
		}
	}

	// 3. Raising the share moves buckets in between to the new release and nobody back.
	site.Rollout.Percent = 70
	write(site)
	reload()
	moved := 0
	for _, v := range people {
		body, _, _ := get(v.client, "/")
		if v.version == "new" && body != "new" {
			t.Fatalf("a visitor went back to the old release (bucket %s)", v.bucket)
		}
		if v.version == "old" && body == "new" {
			moved++
			if v.bucket >= "70" {
				t.Fatalf("bucket %s moved at 70%%", v.bucket)
			}
		}
	}
	if moved < 100 {
		t.Errorf("only %d visitors moved when the share went from 30%% to 70%%", moved)
	}

	// 4. Failures are counted against the container that failed.
	for i := 0; i < 30; i++ {
		jar, _ := cookiejar.New(nil)
		get(&http.Client{Jar: jar}, "/fail")
	}
	st := counts()
	if st[20002] == nil || st[20002].Errors == 0 {
		t.Errorf("failures of the new container were not counted: %+v", st[20002])
	}
	if st[20001] != nil && st[20001].Errors != 0 {
		t.Errorf("the old container was blamed: %+v", st[20001])
	}
	if st[20001] == nil || st[20002] == nil || st[20001].Requests+st[20002].Requests < 400 {
		t.Errorf("counts = %+v %+v", st[20001], st[20002])
	}
}
