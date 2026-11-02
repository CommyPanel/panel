// Package statedir is the agent's own private folder: its scratch files, the
// record of the dumps it made, the TLS private keys, the ACME account key and
// the ACME challenge files.
//
// The panel's data directory belongs to the panel user. Anything the root agent
// writes there, or trusts from there, can be swapped or forged by a compromised
// panel: a file replaced by a link to a root-only file, a folder renamed away and
// made again so that new private keys are written where the panel can read them.
// This folder lives outside it, is owned by whoever runs the agent and is
// writable by nobody else, which is what makes what is inside it trustworthy.
package statedir

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// Folders of the state directory and their modes. The state directory itself
// is 0711 (set by systemd), so the web server can reach acme-webroot but list
// nothing; everything else is for the agent alone.
var folders = map[string]os.FileMode{
	"tmp":          0o700,
	"dumps":        0o700,
	"certs":        0o700,
	"acme":         0o700,
	"acme-webroot": 0o755, // nginx reads the challenge files from here
}

// Default is where a development setup (and the tests) keep it, below the data
// directory. A real install always passes --state-dir.
func Default(dataDir string) string { return filepath.Join(dataDir, "agent-state") }

// Prepare creates the state directory and its folders if they are missing and
// refuses to go on if any of them is not private to the agent. It runs when the
// agent starts.
func Prepare(root string) error {
	if err := os.MkdirAll(root, 0o711); err != nil {
		return err
	}
	if err := Check(root); err != nil {
		return err
	}
	for name, mode := range folders {
		dir := filepath.Join(root, name)
		if err := os.MkdirAll(dir, mode); err != nil {
			return err
		}
		if err := Check(dir); err != nil {
			return err
		}
	}
	return nil
}

// Check refuses a folder that is a link, belongs to someone else or can be
// written by anyone but its owner.
func Check(dir string) error {
	info, err := os.Lstat(dir)
	if err != nil {
		return err
	}
	st, ok := info.Sys().(*syscall.Stat_t)
	if !info.IsDir() || !ok || int(st.Uid) != os.Geteuid() || info.Mode().Perm()&0o022 != 0 {
		return fmt.Errorf("%s is not a private folder of the agent", dir)
	}
	return nil
}

// MinFreeBytes is how much free space the disk must have before the agent starts
// writing a dump, a restore copy or a volume archive: these can be large, and a
// full disk breaks the databases and the panel along with them.
const MinFreeBytes = 2 << 30

// EnoughSpace reports an error when the disk holding dir has less than min free.
func EnoughSpace(dir string, min uint64) error {
	var st syscall.Statfs_t
	if err := syscall.Statfs(dir, &st); err != nil {
		return err
	}
	if free := uint64(st.Bavail) * uint64(st.Bsize); free < min {
		return fmt.Errorf("the disk has only %d MB free, which is too little to start this", free>>20)
	}
	return nil
}
