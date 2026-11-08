package docker

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/validate"
	"commypanel/agent/internal/volumefs"
)

// volumeRestoreMargin is the room left on the disk beyond what an archive unpacks to.
const volumeRestoreMargin = 1 << 30

// RegisterVolumeArchive remembers the checksum (hex SHA-256) of a volume
// archive the agent made, like RegisterDump does for dumps, in a folder of its own.
func (m *Manager) RegisterVolumeArchive(sum string) error {
	if !sha256Hex.MatchString(sum) {
		return fmt.Errorf("not a checksum")
	}
	dir, err := m.stateDir("volume-archives")
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(dir, sum), nil, 0o600)
}

func (m *Manager) volumeArchiveRegistered(sum string) bool {
	if !sha256Hex.MatchString(sum) {
		return false
	}
	dir, err := m.stateDir("volume-archives")
	if err != nil {
		return false
	}
	info, err := os.Lstat(filepath.Join(dir, sum))
	return err == nil && info.Mode().IsRegular()
}

// copyVolumeArchive copies an archive out of the panel's backup folder into a
// private file while hashing it, and refuses it unless this agent made it.
func (m *Manager) copyVolumeArchive(app, file string) (string, error) {
	root, err := os.OpenRoot(filepath.Join(m.cfg.DataDir, "backups", "volumes"))
	if err != nil {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	defer root.Close()

	rel := filepath.Join(app, file)
	if info, err := root.Lstat(rel); err != nil || !info.Mode().IsRegular() {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	src, err := root.Open(rel)
	if err != nil {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	defer src.Close()

	tmpDir, err := m.scratch()
	if err != nil {
		return "", err
	}
	dst, err := os.CreateTemp(tmpDir, "volrestore-*.tar.gz")
	if err != nil {
		return "", err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(dst, h), src)
	if cerr := dst.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(dst.Name())
		return "", err
	}
	if !m.volumeArchiveRegistered(hex.EncodeToString(h.Sum(nil))) {
		os.Remove(dst.Name())
		return "", &validate.InputError{Msg: "this file was not made by CommyPanel, or was changed since, so it is not restored"}
	}
	return dst.Name(), nil
}

// runningWith returns the name of a running container that has path (or a
// folder above or below it) mounted, or "". Docker being unreachable is an
// error, not "nothing runs": the answer decides whether files are deleted.
func (m *Manager) runningWith(ctx context.Context, path string) (string, error) {
	var raw []struct {
		Names  []string `json:"Names"`
		State  string   `json:"State"`
		Mounts []struct {
			Source string `json:"Source"`
		} `json:"Mounts"`
	}
	err := m.engine.doJSON(ctx, "GET", "/containers/json", url.Values{}, nil, &raw)
	if err != nil {
		if errors.Is(err, ErrUnavailable) {
			return "", &validate.InputError{Msg: "Docker is not running, so it cannot be checked that nothing still uses this volume"}
		}
		return "", err
	}
	sep := string(filepath.Separator)
	for _, c := range raw {
		if c.State != "" && c.State != "running" && c.State != "restarting" {
			continue
		}
		for _, mt := range c.Mounts {
			if mt.Source == path || strings.HasPrefix(mt.Source, path+sep) || strings.HasPrefix(path, strings.TrimSuffix(mt.Source, sep)+sep) {
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

// RestoreVolume replaces the content of an app's volume folder with an archive
// the agent made earlier. The archive is copied out of the panel's backup
// folder and checked against the record of archives the agent made, scanned
// (nothing unsafe, nothing too big, room on the disk) and only then unpacked,
// into a hidden sibling folder that is swapped in at the end. It refuses while a
// container that has the volume mounted is running.
func (m *Manager) RestoreVolume(ctx context.Context, app, volume, archive string) (volumefs.Summary, error) {
	if err := validate.Name(app); err != nil {
		return volumefs.Summary{}, err
	}
	if err := validate.VolumeName(volume); err != nil {
		return volumefs.Summary{}, err
	}
	if err := validate.VolumeArchive(archive); err != nil {
		return volumefs.Summary{}, err
	}
	if m.cfg.DryRun {
		return volumefs.Summary{}, &validate.InputError{Msg: "restoring is not available in dry-run mode"}
	}
	dir, err := validate.ResolveUnder(m.volumesDir(), filepath.Join(m.volumesDir(), app, volume))
	if err != nil {
		return volumefs.Summary{}, err
	}
	if info, err := os.Lstat(dir); err != nil || !info.IsDir() {
		return volumefs.Summary{}, &validate.InputError{Msg: "this volume does not exist"}
	}
	if name, err := m.runningWith(ctx, dir); err != nil {
		return volumefs.Summary{}, err
	} else if name != "" {
		return volumefs.Summary{}, &validate.InputError{Msg: fmt.Sprintf("container %s is running with this volume mounted; stop the app first", name)}
	}

	tmp, err := m.copyVolumeArchive(app, archive)
	if err != nil {
		return volumefs.Summary{}, err
	}
	defer os.Remove(tmp)

	f, err := os.Open(tmp)
	if err != nil {
		return volumefs.Summary{}, err
	}
	scanned, err := volumefs.Scan(ctx, f)
	f.Close()
	if err != nil {
		return volumefs.Summary{}, err
	}
	need := uint64(scanned.Bytes) + volumeRestoreMargin
	if err := statedir.EnoughSpace(filepath.Dir(dir), need); err != nil {
		return volumefs.Summary{}, &validate.InputError{Msg: err.Error()}
	}

	sum, err := volumefs.Replace(ctx, m.volumesDir(), app, volume, tmp, os.Geteuid() == 0)
	if err != nil {
		return volumefs.Summary{}, err
	}
	slog.Info("restored a volume", "app", app, "volume", volume, "files", sum.Files)
	return sum, nil
}
