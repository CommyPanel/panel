//go:build integration

package docker

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

// Run with: go test -tags integration ./internal/docker -run Real -v
// Needs a local Docker daemon. Uses a FROM scratch image, so nothing is pulled.
func TestRealDocker(t *testing.T) {
	sock := os.Getenv("DOCKER_SOCK")
	if sock == "" {
		sock = "/var/run/docker.sock"
	}
	dataDir := t.TempDir()
	m := NewManager(Config{DataDir: dataDir}, NewUnixEngine(sock))
	ctx := context.Background()

	id := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000)
	tag := "commypanel/it-" + id + ":r1"
	name := "it-" + id
	network := "cp-it-" + id
	t.Cleanup(func() {
		_ = m.Remove(ctx, name)
		exec.Command("docker", "rmi", "-f", tag).Run()
		exec.Command("docker", "network", "rm", network).Run()
	})

	// A static Go HTTP server for the image's own architecture.
	buildDir := filepath.Join(dataDir, "builds", "it")
	srcDir := t.TempDir()
	os.MkdirAll(buildDir, 0o755)
	os.WriteFile(filepath.Join(srcDir, "main.go"), []byte(`package main
import ("fmt";"net/http";"os")
func main(){
 if len(os.Args)>1 && os.Args[1]=="release" { fmt.Println("release ran"); os.Exit(3) }
 fmt.Println("server starting")
 http.HandleFunc("/",func(w http.ResponseWriter,r *http.Request){fmt.Fprintf(w,"hello %s",os.Getenv("GREETING"))})
 http.ListenAndServe(":8080",nil)
}`), 0o644)
	os.WriteFile(filepath.Join(srcDir, "go.mod"), []byte("module it\n\ngo 1.22\n"), 0o644)
	cmd := exec.Command("go", "build", "-o", filepath.Join(buildDir, "server"), ".")
	cmd.Dir = srcDir
	cmd.Env = append(os.Environ(), "CGO_ENABLED=0", "GOOS=linux", "GOARCH="+runtime.GOARCH)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("compile test server: %v\n%s", err, out)
	}
	os.WriteFile(filepath.Join(buildDir, "Dockerfile"), []byte("FROM scratch\nCOPY server /server\nEXPOSE 8080\nCMD [\"/server\"]\n"), 0o644)

	var lines []string
	if err := m.Build(ctx, BuildSpec{Tag: tag, ContextPath: buildDir}, func(l string) { lines = append(lines, l) }); err != nil {
		t.Fatalf("build: %v (output: %v)", err, lines)
	}
	if len(lines) == 0 {
		t.Error("expected build output")
	}

	spec := ContainerSpec{Name: name, Image: tag, HostPort: 29871, ContainerPort: 8080,
		Env: map[string]string{"GREETING": "commypanel"}, Network: network, MemoryMB: 64}
	if err := m.Run(ctx, spec); err != nil {
		t.Fatalf("run: %v", err)
	}

	var body string
	for i := 0; i < 30; i++ {
		resp, err := http.Get("http://127.0.0.1:29871/")
		if err == nil {
			b, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			body = string(b)
			break
		}
		time.Sleep(200 * time.Millisecond)
	}
	if body != "hello commypanel" {
		t.Errorf("response = %q", body)
	}

	list, err := m.List(ctx)
	found := false
	for _, c := range list {
		found = found || (c.Name == name && c.State == "running")
	}
	if err != nil || !found {
		t.Errorf("list = %v, %v", list, err)
	}

	logs, err := m.Logs(ctx, name, 50)
	if err != nil || !strings.Contains(logs, "server starting") {
		t.Errorf("logs = %q, %v", logs, err)
	}

	if err := m.Restart(ctx, name); err != nil {
		t.Errorf("restart: %v", err)
	}
	if err := m.Stop(ctx, name); err != nil {
		t.Errorf("stop: %v", err)
	}
	if err := m.Start(ctx, name); err != nil {
		t.Errorf("start: %v", err)
	}
	if err := m.Start(ctx, name); err != nil {
		t.Errorf("starting a running container should succeed: %v", err)
	}

	var out []string
	task := TaskSpec{Name: name + "-release", Image: tag, Command: []string{"/server", "release"}, Network: network, TimeoutSeconds: 30}
	code, err := m.RunTask(ctx, task, func(l string) { out = append(out, l) })
	if err != nil || code != 3 || strings.Join(out, "|") != "release ran" {
		t.Errorf("task: code=%d err=%v output=%v", code, err, out)
	}
	if exec.Command("docker", "inspect", name+"-release").Run() == nil {
		t.Error("the task container was not removed")
	}

	task.Command = []string{"/server"} // serves forever
	task.TimeoutSeconds = 2
	if _, err := m.RunTask(ctx, task, func(string) {}); err == nil || !strings.Contains(err.Error(), "did not finish") {
		t.Errorf("timeout: err = %v", err)
	}
	if exec.Command("docker", "inspect", name+"-release").Run() == nil {
		t.Error("the timed-out task container was not removed")
	}

	if err := m.Stop(ctx, name); err != nil {
		t.Errorf("stop: %v", err)
	}
	if err := m.Remove(ctx, name); err != nil {
		t.Errorf("remove: %v", err)
	}
	if err := m.Remove(ctx, name); err != nil {
		t.Errorf("remove again should succeed: %v", err)
	}

	// Safety: a container we did not create must be refused.
	if out, err := exec.Command("docker", "run", "-d", "--name", name+"-foreign", tag).CombinedOutput(); err != nil {
		t.Logf("could not start foreign container: %v %s", err, out)
	} else {
		defer exec.Command("docker", "rm", "-f", name+"-foreign").Run()
		if err := m.Remove(ctx, name+"-foreign"); err == nil {
			t.Error("must refuse to remove a container without the managed label")
		}
	}
}
