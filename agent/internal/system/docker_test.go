package system

import (
	"context"
	"errors"
	"os/exec"
	"strings"
	"testing"
)

func TestDiagnoseRunsOnlyFixedCommands(t *testing.T) {
	var ran []string
	u := &DockerUnit{Run: func(_ context.Context, name string, args ...string) ([]byte, error) {
		ran = append(ran, name+" "+strings.Join(args, " "))
		switch {
		case name == "journalctl":
			return []byte("line one\nfailed to start daemon\n"), nil
		case args[1] == "docker.service":
			return []byte("failed\n"), errors.New("exit 3")
		default:
			return []byte("active\n"), nil
		}
	}}

	d := u.Diagnose(context.Background())

	if d.Service != "failed" || d.Socket != "active" || d.Journal != "line one\nfailed to start daemon" {
		t.Errorf("diagnosis = %+v", d)
	}
	want := []string{
		"journalctl --unit=docker.service --lines=60 --no-pager --output=short-iso",
		"systemctl is-active docker.service",
		"systemctl is-active docker.socket",
	}
	if strings.Join(ran, "|") != strings.Join(want, "|") {
		t.Errorf("ran = %v", ran)
	}
}

func TestDiagnoseKeepsTheEndOfALongJournal(t *testing.T) {
	long := strings.Repeat("x", maxJournalBytes) + "the end"
	u := &DockerUnit{Run: func(_ context.Context, name string, _ ...string) ([]byte, error) {
		if name == "journalctl" {
			return []byte(long), nil
		}
		return nil, errors.New("no systemd")
	}}

	d := u.Diagnose(context.Background())
	if len(d.Journal) != maxJournalBytes || !strings.HasSuffix(d.Journal, "the end") || d.Service != "unknown" {
		t.Errorf("journal length %d, service %q", len(d.Journal), d.Service)
	}
}

func TestRestartRunsSystemctlAndReportsItsError(t *testing.T) {
	var ran string
	u := &DockerUnit{Run: func(_ context.Context, name string, args ...string) ([]byte, error) {
		ran = name + " " + strings.Join(args, " ")
		return nil, &exec.ExitError{Stderr: []byte("Job for docker.service failed.\n")}
	}}

	err := u.Restart(context.Background())
	if ran != "systemctl restart docker.service" {
		t.Errorf("ran %q", ran)
	}
	if err == nil || err.Error() != "restarting Docker failed: Job for docker.service failed." {
		t.Errorf("err = %v", err)
	}
}

func TestDryRunNeverRunsCommands(t *testing.T) {
	u := &DockerUnit{DryRun: true, Run: func(context.Context, string, ...string) ([]byte, error) {
		t.Fatal("dry-run must not run commands")
		return nil, nil
	}}
	if err := u.Restart(context.Background()); err != nil {
		t.Fatal(err)
	}
	if d := u.Diagnose(context.Background()); d.Service != "unknown" {
		t.Errorf("diagnosis = %+v", d)
	}
}
