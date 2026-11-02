package docker

import (
	"os"
	"path/filepath"

	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/validate"
)

// stateDir returns a folder inside the agent's state directory, creating it.
//
// The panel's data directory belongs to the panel user, so anything the agent
// writes there, or trusts from there, can be replaced by a compromised panel
// (a file swapped for a link to a root-only file, a folder renamed away and
// made again). Scratch files and the record of which dumps the agent made
// therefore live in a directory that is outside it, owned by whoever runs the
// agent and writable by nobody else. This checks that every time it is used.
func (m *Manager) stateDir(sub string) (string, error) {
	root := m.cfg.StateDir
	if err := os.MkdirAll(root, 0o711); err != nil {
		return "", err
	}
	if err := statedir.Check(root); err != nil {
		return "", err
	}
	dir := filepath.Join(root, sub)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	if err := statedir.Check(dir); err != nil {
		return "", err
	}
	return dir, nil
}

// CleanScratch removes what a crashed dump or restore left behind. It runs
// when the agent starts, before any request.
func (m *Manager) CleanScratch() {
	dir, err := m.stateDir("tmp")
	if err != nil {
		return
	}
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		_ = os.RemoveAll(filepath.Join(dir, e.Name()))
	}
}

// ScratchDir is the agent's private folder for files it makes and then sends or
// loads (dumps, restores, volume archives).
func (m *Manager) ScratchDir() (string, error) { return m.scratch() }

// scratch is the private tmp folder, once the disk has room for a big file.
func (m *Manager) scratch() (string, error) {
	dir, err := m.stateDir("tmp")
	if err != nil {
		return "", err
	}
	if err := statedir.EnoughSpace(dir, statedir.MinFreeBytes); err != nil {
		return "", &validate.InputError{Msg: err.Error()}
	}
	return dir, nil
}
