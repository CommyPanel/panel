package system

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os/exec"
	"strings"
	"time"
)

const maxJournalBytes = 64 << 10

// DockerUnit inspects and restarts the Docker daemon's systemd unit, so the
// panel can show why Docker is down and bring it back. Every command is
// fixed; nothing the panel sends ends up in them.
type DockerUnit struct {
	Run    Runner
	DryRun bool
}

// NewDockerUnit uses the real systemctl and journalctl.
func NewDockerUnit(dryRun bool) *DockerUnit {
	return &DockerUnit{DryRun: dryRun, Run: func(ctx context.Context, name string, args ...string) ([]byte, error) {
		return exec.CommandContext(ctx, name, args...).Output()
	}}
}

// DockerDiagnosis is what systemd knows about the Docker daemon.
type DockerDiagnosis struct {
	Service string `json:"service"` // state of docker.service: active, failed, inactive, ...
	Socket  string `json:"socket"`  // state of docker.socket
	Journal string `json:"journal"` // the daemon's latest log lines
}

// Diagnose reads the unit states and the end of the daemon's journal.
func (u *DockerUnit) Diagnose(ctx context.Context) DockerDiagnosis {
	if u.DryRun {
		return DockerDiagnosis{Service: "unknown", Socket: "unknown", Journal: "dry-run: systemd is not contacted"}
	}
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	journal, _ := u.Run(ctx, "journalctl", "--unit=docker.service", "--lines=60", "--no-pager", "--output=short-iso")
	if len(journal) > maxJournalBytes {
		journal = journal[len(journal)-maxJournalBytes:]
	}
	return DockerDiagnosis{
		Service: u.state(ctx, "docker.service"),
		Socket:  u.state(ctx, "docker.socket"),
		Journal: strings.TrimRight(string(journal), "\n"),
	}
}

func (u *DockerUnit) state(ctx context.Context, unit string) string {
	// is-active exits non-zero for anything but "active" but still prints the state.
	b, _ := u.Run(ctx, "systemctl", "is-active", unit)
	if s := strings.TrimSpace(string(b)); s != "" {
		return s
	}
	return "unknown"
}

// Restart restarts the Docker daemon. Containers stop with it and come back
// through their restart policy.
func (u *DockerUnit) Restart(ctx context.Context) error {
	if u.DryRun {
		slog.Info("dry-run: would restart docker.service")
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()

	if _, err := u.Run(ctx, "systemctl", "restart", "docker.service"); err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) && len(exit.Stderr) > 0 {
			return fmt.Errorf("restarting Docker failed: %s", strings.TrimSpace(string(exit.Stderr)))
		}
		return fmt.Errorf("restarting Docker failed: %w", err)
	}
	slog.Info("docker.service restarted")
	return nil
}
