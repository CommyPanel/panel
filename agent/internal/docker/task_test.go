package docker

import (
	"context"
	"errors"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

func taskSpec() TaskSpec {
	return TaskSpec{Name: "demo-1-release", Image: "commypanel/demo:1", Command: []string{"php", "artisan", "migrate", "--force"}}
}

func TestStartAndRestartOnlyTouchExistingManagedContainers(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()

	if err := m.Start(ctx, "demo-1"); err != nil || !d.has("POST /containers/demo-1/start") {
		t.Fatalf("start: %v %v", err, d.requests)
	}
	if err := m.Restart(ctx, "demo-1"); err != nil || !d.has("POST /containers/demo-1/restart") {
		t.Fatalf("restart: %v %v", err, d.requests)
	}

	d.labels = map[string]string{"other": "x"}
	if err := m.Start(ctx, "demo-1"); !isInputError(err) {
		t.Errorf("foreign container: err = %v, want input error", err)
	}

	d.labels = nil
	if err := m.Restart(ctx, "demo-1"); !isInputError(err) {
		t.Errorf("missing container: err = %v, want input error", err)
	}
	if err := m.Start(ctx, "Bad Name"); !isInputError(err) {
		t.Errorf("bad name: err = %v, want input error", err)
	}
}

func TestRunTaskStreamsOutputReturnsExitCodeAndCleansUp(t *testing.T) {
	m, d, _ := setup(t)
	d.waitCode = 2
	var lines []string

	code, err := m.RunTask(context.Background(), taskSpec(), func(l string) { lines = append(lines, l) })
	if err != nil {
		t.Fatal(err)
	}
	if code != 2 {
		t.Errorf("exit code = %d, want 2", code)
	}
	if strings.Join(lines, "|") != "hello|world" {
		t.Errorf("lines = %v", lines)
	}

	cmd, _ := d.created["Cmd"].([]any)
	if len(cmd) != 4 || cmd[0] != "php" || cmd[3] != "--force" {
		t.Errorf("Cmd = %v", d.created["Cmd"])
	}
	host := d.created["HostConfig"].(map[string]any)
	if host["Privileged"] != false || host["RestartPolicy"].(map[string]any)["Name"] != "no" {
		t.Errorf("host config = %v", host)
	}
	if _, published := host["PortBindings"]; published {
		t.Error("a task must not publish ports")
	}
	if d.created["Labels"].(map[string]any)[ManagedLabel] != "true" {
		t.Error("task container is not labelled as managed")
	}
	if !d.has("POST /containers/demo-1-release/start") || !d.has("DELETE /containers/demo-1-release") {
		t.Errorf("requests = %v", d.requests)
	}
}

func TestRunTaskRefusesBadSpecs(t *testing.T) {
	m, d, _ := setup(t)
	cases := map[string]func(*TaskSpec){
		"foreign image":   func(s *TaskSpec) { s.Image = "library/alpine:3" },
		"no command":      func(s *TaskSpec) { s.Command = nil },
		"empty program":   func(s *TaskSpec) { s.Command = []string{"", "x"} },
		"nul byte":        func(s *TaskSpec) { s.Command = []string{"sh", "-c", "a\x00b"} },
		"too many args":   func(s *TaskSpec) { s.Command = make([]string, maxTaskArgs+1); s.Command[0] = "x" },
		"long argument":   func(s *TaskSpec) { s.Command = []string{"echo", strings.Repeat("a", maxTaskArgBytes+1)} },
		"timeout too big": func(s *TaskSpec) { s.TimeoutSeconds = maxTaskTimeout + 1 },
		"bad name":        func(s *TaskSpec) { s.Name = "../x" },
		"bad env":         func(s *TaskSpec) { s.Env = map[string]string{"A B": "1"} },
	}
	for name, mutate := range cases {
		spec := taskSpec()
		mutate(&spec)
		if _, err := m.RunTask(context.Background(), spec, func(string) {}); !isInputError(err) {
			t.Errorf("%s: err = %v, want input error", name, err)
		}
	}
	if len(d.requests) != 0 {
		t.Errorf("invalid specs reached Docker: %v", d.requests)
	}
}

func TestRunTaskInDryRunOnlyDescribesIt(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, nil)
	var lines []string
	code, err := m.RunTask(context.Background(), taskSpec(), func(l string) { lines = append(lines, l) })
	if err != nil || code != 0 || len(lines) != 1 || !strings.Contains(lines[0], "php artisan migrate") {
		t.Errorf("code=%d err=%v lines=%v", code, err, lines)
	}
}

func TestLineWriterSplitsLongLinesAndTruncates(t *testing.T) {
	var lines []string
	w := &lineWriter{onLine: func(l string) { lines = append(lines, l) }, limit: maxTaskLine + 10}

	_, _ = w.Write([]byte("one\r\ntw"))
	_, _ = w.Write([]byte("o\n"))
	if strings.Join(lines, "|") != "one|two" {
		t.Fatalf("lines = %v", lines)
	}

	lines = nil
	_, _ = w.Write([]byte(strings.Repeat("x", maxTaskLine+100)))
	_, _ = w.Write([]byte("ignored\n"))
	w.flush()
	if len(lines) != 3 || len(lines[0]) != maxTaskLine || lines[2] != "[output truncated]" {
		t.Errorf("got %d lines, last %q", len(lines), lines[len(lines)-1])
	}
}

func isInputError(err error) bool {
	var input *validate.InputError
	return errors.As(err, &input)
}
