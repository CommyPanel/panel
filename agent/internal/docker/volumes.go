package docker

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/url"
	"os"
	"path/filepath"
	"strings"

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
// a volume that does not exist is not an error. It refuses while any container,
// running or not, still has the folder mounted, because deleting it under a
// running app would break the app.
func (m *Manager) RemoveVolumes(ctx context.Context, app, name string) error {
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
	if user, err := m.mountedBy(ctx, resolved, false); err != nil {
		return err
	} else if user != "" {
		return &validate.InputError{Msg: fmt.Sprintf("container %s still has this volume mounted; deploy the app without it first", user)}
	}
	if err := os.RemoveAll(resolved); err != nil {
		return fmt.Errorf("remove volume data: %w", err)
	}
	return nil
}

// mountedBy returns the name of a container that has path (or something inside
// it) mounted, or "". When the Docker daemon cannot be reached nothing can be
// running, so there is nothing to protect.
func (m *Manager) mountedBy(ctx context.Context, path string, strict bool) (string, error) {
	var raw []struct {
		Names  []string `json:"Names"`
		Mounts []struct {
			Source string `json:"Source"`
		} `json:"Mounts"`
	}
	err := m.engine.doJSON(ctx, "GET", "/containers/json", url.Values{"all": {"true"}}, nil, &raw)
	if errors.Is(err, ErrUnavailable) {
		if strict {
			return "", &validate.InputError{Msg: "Docker is not running, so it cannot be checked that nothing still uses this folder"}
		}
		return "", nil
	}
	if err != nil {
		return "", err
	}
	for _, c := range raw {
		for _, mount := range c.Mounts {
			if mount.Source == path || strings.HasPrefix(mount.Source, path+string(filepath.Separator)) {
				name := "(unnamed)"
				if len(c.Names) > 0 {
					name = strings.TrimPrefix(c.Names[0], "/")
				}
				return name, nil
			}
		}
	}
	return "", nil
}

// prepareMount creates the folder behind a mount the first time it is used. An
// app's volume is open to whichever user the app runs as (see ensureVolumeDir).
// A database server's data folder stays private to root: the database image
// takes it over for its own user when it starts, and no other user should ever
// be able to open it.
func (m *Manager) prepareMount(source string) error {
	if _, err := validate.ResolveUnder(m.databasesDir(), source); err == nil {
		return os.MkdirAll(source, 0o700)
	}
	return ensureVolumeDir(source)
}
