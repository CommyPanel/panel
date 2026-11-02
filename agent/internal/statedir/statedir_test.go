package statedir

import (
	"os"
	"path/filepath"
	"testing"
)

func TestPrepareMakesTheFoldersWithTheRightModes(t *testing.T) {
	root := filepath.Join(t.TempDir(), "state")
	if err := Prepare(root); err != nil {
		t.Fatal(err)
	}
	want := map[string]os.FileMode{"": 0o711, "tmp": 0o700, "dumps": 0o700, "certs": 0o700, "acme": 0o700, "acme-webroot": 0o755}
	for name, mode := range want {
		info, err := os.Stat(filepath.Join(root, name))
		if err != nil {
			t.Fatal(err)
		}
		if info.Mode().Perm() != mode {
			t.Errorf("%q is %v, want %v", name, info.Mode().Perm(), mode)
		}
	}
	if err := Prepare(root); err != nil {
		t.Errorf("preparing again: %v", err)
	}
}

func TestPrepareRefusesAFolderOthersCanWriteToOrALinkInsteadOfOne(t *testing.T) {
	root := filepath.Join(t.TempDir(), "state")
	if err := Prepare(root); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(filepath.Join(root, "certs"), 0o777); err != nil {
		t.Fatal(err)
	}
	if err := Prepare(root); err == nil {
		t.Error("a world-writable certs folder was accepted")
	}
	if err := os.Chmod(filepath.Join(root, "certs"), 0o700); err != nil {
		t.Fatal(err)
	}

	other := t.TempDir()
	if err := os.RemoveAll(filepath.Join(root, "acme")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(other, filepath.Join(root, "acme")); err != nil {
		t.Fatal(err)
	}
	if err := Prepare(root); err == nil {
		t.Error("a link in place of the acme folder was accepted")
	}
}

func TestEnoughSpaceRefusesWhenTheDiskIsTooFull(t *testing.T) {
	dir := t.TempDir()
	if err := EnoughSpace(dir, 1); err != nil {
		t.Errorf("a byte should be free: %v", err)
	}
	if err := EnoughSpace(dir, 1<<60); err == nil {
		t.Error("an exabyte cannot be free")
	}
}
