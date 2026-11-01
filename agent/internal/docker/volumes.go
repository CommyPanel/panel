package docker

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"

	"commypanel/agent/internal/validate"
)

// ensureVolumeDir creates the folder behind a volume the first time it is used.
//
// Apps run as an ordinary user whose id differs from image to image (node,
// nonroot, app, ...), and the agent cannot know which, so the folder an app
// sees is open to every user. That is safe because everything above it is
// private to root (0700): nobody on the host can reach it, and no other
// container has it mounted. A folder that already exists is left as it is.
func ensureVolumeDir(source string) error {
	if err := os.MkdirAll(filepath.Dir(source), 0o700); err != nil {
		return err
	}
	if err := os.Mkdir(source, 0o777); err != nil {
		if os.IsExist(err) {
			return nil
		}
		return err
	}
	return os.Chmod(source, 0o777) // Mkdir is subject to the umask
}

// RemoveVolumes deletes the data of one volume of an app, or of all of them
// when name is empty. It never follows links out of the volumes directory, and
// a volume that does not exist is not an error. The caller makes sure nothing
// still mounts it: removing a mounted folder would break the running app.
func (m *Manager) RemoveVolumes(_ context.Context, app, name string) error {
	if err := validate.Name(app); err != nil {
		return err
	}
	target := filepath.Join(m.volumesDir(), app)
	if name != "" {
		if err := validate.VolumeName(name); err != nil {
			return err
		}
		target = filepath.Join(target, name)
	}
	resolved, err := validate.ResolveUnder(m.volumesDir(), target)
	if err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would remove volume data", "path", resolved)
		return nil
	}
	if err := os.RemoveAll(resolved); err != nil {
		return fmt.Errorf("remove volume data: %w", err)
	}
	return nil
}
