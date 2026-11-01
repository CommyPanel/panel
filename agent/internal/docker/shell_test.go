package docker

import (
	"context"
	"errors"
	"io"
	"testing"
	"time"

	"commypanel/agent/internal/validate"
)

func TestOpenShellRunsAnUnprivilegedShellWithATerminalAndEchoesBothWays(t *testing.T) {
	m, d, _ := setup(t)

	sh, err := m.OpenShell(context.Background(), "demo-1", 100, 30)
	if err != nil {
		t.Fatal(err)
	}
	defer sh.Close()

	d.mu.Lock()
	body, resized, upgrade := d.execBody, d.resized, d.upgrade
	d.mu.Unlock()
	if body["Tty"] != true || body["Privileged"] != false || body["AttachStdin"] != true {
		t.Errorf("exec options = %v", body)
	}
	if cmd, _ := body["Cmd"].([]any); len(cmd) != 1 || cmd[0] != "/bin/sh" {
		t.Errorf("command = %v", body["Cmd"])
	}
	if _, set := body["User"]; set {
		t.Error("the shell must run as the container's own user, not an overridden one")
	}
	if upgrade != "tcp" {
		t.Errorf("Upgrade header = %q", upgrade)
	}
	if resized != "h=30&w=100" {
		t.Errorf("initial size = %q", resized)
	}

	if _, err := sh.Write([]byte("ls\n")); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 64)
	done := make(chan struct{})
	go func() {
		n, _ := io.ReadAtLeast(sh, buf, len("echo:ls\n"))
		if string(buf[:n]) != "echo:ls\n" {
			t.Errorf("got %q", buf[:n])
		}
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(3 * time.Second):
		t.Fatal("no echo from the container")
	}

	if err := sh.Resize(120, 40); err != nil {
		t.Error(err)
	}
	for _, bad := range [][2]int{{0, 10}, {10, 0}, {1001, 10}, {10, 1001}, {-1, 5}} {
		if err := sh.Resize(bad[0], bad[1]); err == nil {
			t.Errorf("size %v must be refused", bad)
		}
	}
}

func TestOpenShellRefusesWhatItShould(t *testing.T) {
	m, d, _ := setup(t)

	for _, name := range []string{"../etc", "Demo", ""} {
		if _, err := m.OpenShell(context.Background(), name, 80, 24); err == nil {
			t.Errorf("name %q must be refused", name)
		}
	}
	for _, size := range [][2]int{{0, 24}, {80, 0}, {5000, 24}} {
		if _, err := m.OpenShell(context.Background(), "demo-1", size[0], size[1]); err == nil {
			t.Errorf("size %v must be refused", size)
		}
	}

	// A container the agent did not create.
	d.labels = map[string]string{"other": "x"}
	if _, err := m.OpenShell(context.Background(), "demo-1", 80, 24); err == nil {
		t.Error("an unmanaged container must be refused")
	}
	for _, r := range d.requests {
		if r == "POST /containers/demo-1/exec" {
			t.Error("no exec may be created in an unmanaged container")
		}
	}

	// A container that does not exist.
	d.labels = nil
	var ie *validate.InputError
	if _, err := m.OpenShell(context.Background(), "demo-1", 80, 24); !errors.As(err, &ie) {
		t.Errorf("a missing container is an input error, got %v", err)
	}
}

func TestOpenShellSaysWhenTheContainerIsNotRunning(t *testing.T) {
	m, d, _ := setup(t)
	d.execStatus = 409

	_, err := m.OpenShell(context.Background(), "demo-1", 80, 24)
	var ie *validate.InputError
	if !errors.As(err, &ie) || ie.Msg != "the container is not running" {
		t.Errorf("got %v", err)
	}
}

func TestOpenShellNeedsDockerAndNeverRunsInDryRun(t *testing.T) {
	dry := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, NewEngine("http://127.0.0.1:1", nil))
	if _, err := dry.OpenShell(context.Background(), "demo-1", 80, 24); err == nil {
		t.Error("dry-run must not open a shell")
	}
}
