//go:build integration

package dbtool

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"commypanel/agent/internal/docker"
)

const (
	rootPw = "RootPassword1234567890"
	userPw = "ShopPassword1234567890"
	other  = "OtherPassword1234567890"
)

// Run with: go test -tags integration ./internal/dbtool -run RealTools -v -timeout 25m
// Needs a local Docker daemon. Real MariaDB and PostgreSQL servers are started
// with phpMyAdmin and Adminer in front of them, reached through the real proxy.
func TestRealTools(t *testing.T) {
	sock := os.Getenv("DOCKER_SOCK")
	if sock == "" {
		sock = "/var/run/docker.sock"
	}

	for _, c := range []struct{ engine, image string }{
		{"mariadb", "mariadb:11.4"},
		{"postgres", "postgres:17"},
	} {
		t.Run(c.engine, func(t *testing.T) {
			dir, _ := os.MkdirTemp("/tmp", "cptool")
			m := docker.NewManager(docker.Config{DataDir: dir, StateDir: dir + "/state"}, docker.NewUnixEngine(sock))
			ctx := context.Background()

			id := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000)
			server := "it-" + id
			container := "db-" + server
			t.Cleanup(func() {
				_ = m.Remove(ctx, container)
				exec.Command("docker", "network", "rm", "commypanel-db-"+server).Run()
				os.RemoveAll(dir)
			})

			if err := m.PullImage(ctx, c.image); err != nil {
				t.Fatalf("pull: %v", err)
			}
			env := map[string]string{"MARIADB_ROOT_PASSWORD": rootPw}
			if c.engine == "postgres" {
				env = map[string]string{"POSTGRES_PASSWORD": rootPw}
			}
			if err := m.Run(ctx, docker.ContainerSpec{Name: container, Image: c.image, Network: "commypanel-db-" + server, Env: env, MemoryMB: 1024}); err != nil {
				t.Fatalf("run: %v", err)
			}
			target := docker.DatabaseTarget{Container: container, Engine: c.engine, RootPassword: rootPw}
			for deadline := time.Now().Add(150 * time.Second); ; time.Sleep(3 * time.Second) {
				if ok, _ := m.DatabaseReady(ctx, target); ok {
					break
				}
				if time.Now().After(deadline) {
					t.Fatal("the database server never became ready")
				}
			}
			if err := m.CreateDatabase(ctx, target, "shop", "shop", userPw); err != nil {
				t.Fatal(err)
			}
			if err := m.CreateDatabase(ctx, target, "secretdb", "secretuser", other); err != nil {
				t.Fatal(err)
			}

			sessions := New(ctx, m, Config{})
			t.Cleanup(func() {
				for _, id := range []string{} {
					sessions.Stop(id)
				}
			})
			web := httptest.NewServer(sessions)
			defer web.Close()

			view, err := sessions.Start(StartRequest{Engine: c.engine, Server: server, Database: "shop", User: "shop", Password: userPw, PublicBase: "https://tools.example.test:2053"})
			if err != nil {
				t.Fatal(err)
			}
			defer sessions.Stop(view.ID)

			stages := []string{}
			for deadline := time.Now().Add(6 * time.Minute); ; time.Sleep(500 * time.Millisecond) {
				v, ok := sessions.Status(view.ID)
				if !ok {
					t.Fatal("the session vanished")
				}
				if len(stages) == 0 || stages[len(stages)-1] != v.Stage {
					stages = append(stages, v.Stage)
				}
				if v.Stage == StageReady {
					break
				}
				if v.Stage == StageFailed {
					t.Fatalf("failed: %s (stages %v)", v.Error, stages)
				}
				if time.Now().After(deadline) {
					t.Fatalf("never ready, stages %v", stages)
				}
			}
			t.Logf("stages: %v", stages)

			// The container: on its server's network and no other, loopback only, labelled.
			var tool *docker.ContainerInfo
			list, _ := m.List(ctx)
			for i, ci := range list {
				if strings.HasPrefix(ci.Name, "dbtool-") {
					tool = &list[i]
				}
			}
			if tool == nil {
				t.Fatal("no tool container")
			}
			if len(tool.Networks) != 1 || tool.Networks[0].Name != "commypanel-db-"+server {
				t.Errorf("networks = %+v", tool.Networks)
			}
			for _, p := range tool.Ports {
				if p.HostIP != "127.0.0.1" {
					t.Errorf("published on %s", p.HostIP)
				}
			}
			if len(tool.Mounts) != 0 {
				t.Errorf("mounts = %+v", tool.Mounts)
			}

			// Anything on this machine that finds the container's port directly meets the login form,
			// not a logged-in session: the password is not in the container.
			for _, p := range tool.Ports {
				direct, err := http.Get(fmt.Sprintf("http://127.0.0.1:%d/", p.Host))
				if err != nil {
					t.Fatal(err)
				}
				page, _ := io.ReadAll(io.LimitReader(direct.Body, 2<<20))
				direct.Body.Close()
				want := "pma_username"
				if c.engine == "postgres" {
					want = "auth[username]"
				}
				if !strings.Contains(string(page), want) {
					t.Errorf("the container's own port does not show a login form:\n%.400s", page)
				}
				if strings.Contains(string(page), "shop") && !strings.Contains(string(page), want) {
					t.Error("the container's own port is logged in")
				}
			}
			inspect, _ := exec.Command("docker", "inspect", "-f", "{{range .Config.Env}}{{println .}}{{end}}", tool.Name).CombinedOutput()
			if strings.Contains(string(inspect), userPw) || strings.Contains(string(inspect), "PMA_USER") || strings.Contains(string(inspect), "PMA_PASSWORD") {
				t.Errorf("the container's environment holds the login:\n%s", inspect)
			}

			// A browser: no redirects followed, cookies kept by hand (the proxy marks
			// them Secure, and this test talks plain http).
			jar := map[string]string{}
			browser := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }, Timeout: 60 * time.Second}
			fetch := func(path string) (*http.Response, string) {
				req, _ := http.NewRequest("GET", web.URL+path, nil)
				for k, v := range jar {
					req.AddCookie(&http.Cookie{Name: k, Value: v})
				}
				resp, err := browser.Do(req)
				if err != nil {
					t.Fatal(err)
				}
				body, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
				resp.Body.Close()
				for _, ck := range resp.Cookies() {
					jar[ck.Name] = ck.Value
					if ck.Path != "/s/"+view.ID+"/" || !ck.Secure || !ck.HttpOnly {
						t.Errorf("cookie %s: path=%s secure=%v httponly=%v", ck.Name, ck.Path, ck.Secure, ck.HttpOnly)
					}
				}
				return resp, string(body)
			}

			// Without the cookie there is nothing.
			if resp, _ := fetch("/s/" + view.ID + "/"); resp.StatusCode != http.StatusNotFound {
				t.Errorf("without a ticket: %d", resp.StatusCode)
			}

			ticket, err := sessions.Ticket(view.ID)
			if err != nil {
				t.Fatal(err)
			}
			resp, _ := fetch("/open?t=" + url.QueryEscape(ticket))
			if resp.StatusCode != http.StatusSeeOther {
				t.Fatalf("open: %d", resp.StatusCode)
			}
			landing := resp.Header.Get("Location")
			t.Logf("landing: %s", landing)

			// Follow like a browser: redirects stay below the session's path.
			var body string
			for i := 0; i < 6; i++ {
				resp, body = fetch(landing)
				if resp.StatusCode < 300 || resp.StatusCode >= 400 {
					break
				}
				// A browser resolves what the tool says against where it is.
				here, _ := url.Parse(landing)
				next, err := here.Parse(resp.Header.Get("Location"))
				if err != nil || next.Host != "" || !strings.HasPrefix(next.Path, "/s/"+view.ID+"/") {
					t.Fatalf("a redirect left the session: %q from %s", resp.Header.Get("Location"), landing)
				}
				landing = next.RequestURI()
			}
			if resp.StatusCode != 200 {
				t.Fatalf("the tool answered %d: %.300s", resp.StatusCode, body)
			}
			if !strings.Contains(body, "shop") {
				t.Errorf("the page does not mention the database:\n%.600s", body)
			}
			if strings.Contains(body, "secretdb") || strings.Contains(body, "secretuser") {
				t.Error("another database shows on the page")
			}
			if strings.Contains(body, userPw) || strings.Contains(body, rootPw) {
				t.Error("a password is in the page")
			}
			// Every link the page holds that starts with a slash must stay below the session's
			// path; the others are relative to the page, which is already below it.
			for _, attr := range []string{`href="/`, `src="/`, `action="/`, `href='/`, `src='/`} {
				for rest := body; ; {
					i := strings.Index(rest, attr)
					if i < 0 {
						break
					}
					rest = rest[i+len(attr)-1:]
					if !strings.HasPrefix(rest, "/s/"+view.ID+"/") && !strings.HasPrefix(rest, "//") {
						t.Errorf("a link leaves the session path: %.80s", rest)
					}
					rest = rest[1:]
				}
			}

			// The other database must not be reachable through the tool.
			if c.engine == "mariadb" {
				_, dbs := fetch("/s/" + view.ID + "/index.php?route=/server/databases")
				if strings.Contains(dbs, "secretdb") {
					t.Error("phpMyAdmin lists a database the user cannot use")
				}
			} else {
				resp, denied := fetch("/s/" + view.ID + "/?pgsql=db-" + server + "&username=shop&db=secretdb")
				if resp.StatusCode == 200 && strings.Contains(denied, "Schema:") {
					t.Error("Adminer opened a database the user may not use")
				}
			}

			// Closing it removes the container.
			sessions.Stop(view.ID)
			for deadline := time.Now().Add(30 * time.Second); ; time.Sleep(time.Second) {
				names, _ := m.ToolContainers(ctx)
				if len(names) == 0 {
					break
				}
				if time.Now().After(deadline) {
					t.Fatalf("the tool container is still there: %v", names)
				}
			}
			if resp, _ := fetch("/s/" + view.ID + "/"); resp.StatusCode != http.StatusNotFound {
				t.Errorf("a closed session answers: %d", resp.StatusCode)
			}
		})
	}
}
