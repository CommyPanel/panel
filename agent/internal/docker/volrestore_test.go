package docker

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type tarEntry struct {
	name, body string
	typ        byte
	link       string
}

func makeTarGz(t *testing.T, entries []tarEntry) []byte {
	t.Helper()
	var b bytes.Buffer
	zw := gzip.NewWriter(&b)
	tw := tar.NewWriter(zw)
	for _, e := range entries {
		typ := e.typ
		if typ == 0 {
			typ = tar.TypeReg
		}
		h := &tar.Header{Name: e.name, Typeflag: typ, Mode: 0o644, Linkname: e.link, Uid: os.Getuid(), Gid: os.Getgid()}
		if typ == tar.TypeDir {
			h.Mode = 0o755
		}
		if typ == tar.TypeReg {
			h.Size = int64(len(e.body))
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if typ == tar.TypeReg {
			_, _ = tw.Write([]byte(e.body))
		}
	}
	_ = tw.Close()
	_ = zw.Close()
	return b.Bytes()
}

const archiveName = "uploads-20261103-040000.tar.gz"

// volumeSetup makes a volume with one old file and an archive file, registered or not.
func volumeSetup(t *testing.T, entries []tarEntry, register bool) (*Manager, *fakeDaemon, string) {
	t.Helper()
	m, d, dir := setup(t)
	vol := filepath.Join(dir, "volumes", "shop", "uploads")
	if err := os.MkdirAll(vol, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(vol, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(vol, "old.txt"), []byte("old"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(dir, "backups", "volumes", "shop"), 0o700); err != nil {
		t.Fatal(err)
	}
	data := makeTarGz(t, entries)
	if err := os.WriteFile(filepath.Join(dir, "backups", "volumes", "shop", archiveName), data, 0o600); err != nil {
		t.Fatal(err)
	}
	if register {
		sum := sha256.Sum256(data)
		if err := m.RegisterVolumeArchive(hex.EncodeToString(sum[:])); err != nil {
			t.Fatal(err)
		}
	}
	return m, d, vol
}

var goodEntries = []tarEntry{
	{name: "sub/", typ: tar.TypeDir},
	{name: "sub/a.txt", body: "alpha"},
	{name: "b.txt", body: "bravo!"},
}

func exists(p string) bool { _, err := os.Lstat(p); return err == nil }

func TestRestoreVolumeReplacesTheContentAndKeepsTheFolderMode(t *testing.T) {
	m, _, vol := volumeSetup(t, goodEntries, true)

	sum, err := m.RestoreVolume(t.Context(), "shop", "uploads", archiveName)
	if err != nil {
		t.Fatal(err)
	}
	if sum.Files != 2 || sum.Bytes != 11 {
		t.Errorf("summary = %+v", sum)
	}
	if got, _ := os.ReadFile(filepath.Join(vol, "sub", "a.txt")); string(got) != "alpha" {
		t.Errorf("a.txt = %q", got)
	}
	if exists(filepath.Join(vol, "old.txt")) {
		t.Error("old content is still there")
	}
	if got := mode(t, vol); got != 0o750 {
		t.Errorf("folder mode = %o, want 750", got)
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(vol), ".restore-*")); len(left) != 0 {
		t.Errorf("left behind: %v", left)
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(filepath.Dir(filepath.Dir(vol))), "agent-state", "tmp", "volrestore-*")); len(left) != 0 {
		t.Errorf("scratch left behind: %v", left)
	}
}

func TestRestoreVolumeRefusals(t *testing.T) {
	cases := map[string]struct {
		app, volume, archive string
		entries              []tarEntry
		register             bool
		tweak                func(d *fakeDaemon)
		want                 string
	}{
		"bad app":              {"Shop!", "uploads", archiveName, goodEntries, true, nil, "invalid name"},
		"bad volume":           {"shop", "../x", archiveName, goodEntries, true, nil, "invalid volume"},
		"slash in archive":     {"shop", "uploads", "../" + archiveName, goodEntries, true, nil, "not a volume archive"},
		"odd archive name":     {"shop", "uploads", "uploads.tar.gz", goodEntries, true, nil, "not a volume archive"},
		"missing volume":       {"shop", "other", archiveName, goodEntries, true, nil, "does not exist"},
		"missing archive":      {"shop", "uploads", "uploads-20260101-000000.tar.gz", goodEntries, true, nil, "no such backup"},
		"not made by agent":    {"shop", "uploads", archiveName, goodEntries, false, nil, "not made by CommyPanel"},
		"container running":    {"shop", "uploads", archiveName, goodEntries, true, func(d *fakeDaemon) { d.mounts = []string{"@vol"} }, "stop the app first"},
		"parent dir mounted":   {"shop", "uploads", archiveName, goodEntries, true, func(d *fakeDaemon) { d.mounts = []string{"@parent"} }, "stop the app first"},
		"path traversal":       {"shop", "uploads", archiveName, []tarEntry{{name: "ok.txt", body: "x"}, {name: "../../evil", body: "x"}}, true, nil, "leaves the volume"},
		"absolute path":        {"shop", "uploads", archiveName, []tarEntry{{name: "/etc/passwd", body: "x"}}, true, nil, "not allowed"},
		"symlink entry":        {"shop", "uploads", archiveName, []tarEntry{{name: "l", typ: tar.TypeSymlink, link: "/etc"}}, true, nil, "link or special"},
		"hard link entry":      {"shop", "uploads", archiveName, []tarEntry{{name: "l", typ: tar.TypeLink, link: "b"}}, true, nil, "link or special"},
		"device entry":         {"shop", "uploads", archiveName, []tarEntry{{name: "d", typ: tar.TypeChar}}, true, nil, "link or special"},
		"fifo entry":           {"shop", "uploads", archiveName, []tarEntry{{name: "f", typ: tar.TypeFifo}}, true, nil, "link or special"},
		"duplicate file":       {"shop", "uploads", archiveName, []tarEntry{{name: "a", body: "1"}, {name: "a", body: "2"}}, true, nil, "twice"},
		"file under a file":    {"shop", "uploads", archiveName, []tarEntry{{name: "a", body: "1"}, {name: "a/b", body: "2"}}, true, nil, ""},
		"write through a link": {"shop", "uploads", archiveName, []tarEntry{{name: "l", typ: tar.TypeSymlink, link: "/tmp"}, {name: "l/x", body: "x"}}, true, nil, "link or special"},
	}
	for name, c := range cases {
		m, d, vol := volumeSetup(t, c.entries, c.register)
		if c.tweak != nil {
			var mounts []string
			c.tweak(d)
			for _, s := range d.mounts {
				switch s {
				case "@vol":
					mounts = append(mounts, vol)
				case "@parent":
					mounts = append(mounts, filepath.Dir(vol))
				}
			}
			d.mounts = mounts
		}
		_, err := m.RestoreVolume(t.Context(), c.app, c.volume, c.archive)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: got %v, want %q", name, err, c.want)
			continue
		}
		if got, _ := os.ReadFile(filepath.Join(vol, "old.txt")); string(got) != "old" {
			t.Errorf("%s: the old content was touched", name)
		}
		if left, _ := filepath.Glob(filepath.Join(filepath.Dir(vol), ".restore-*")); len(left) != 0 {
			t.Errorf("%s: left behind %v", name, left)
		}
	}
}

func TestRestoreVolumeAllowsAStoppedContainerAndRefusesAChangedArchive(t *testing.T) {
	m, d, vol := volumeSetup(t, goodEntries, true)
	d.mounts = []string{vol}
	d.listState = "exited"
	if _, err := m.RestoreVolume(t.Context(), "shop", "uploads", archiveName); err != nil {
		t.Fatalf("a stopped container must not block: %v", err)
	}

	// Checksum mismatch: the file changed after the agent made it.
	m, _, vol = volumeSetup(t, goodEntries, true)
	changed := makeTarGz(t, []tarEntry{{name: "evil.txt", body: "x"}})
	dir := filepath.Dir(filepath.Dir(filepath.Dir(vol)))
	if err := os.WriteFile(filepath.Join(dir, "backups", "volumes", "shop", archiveName), changed, 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := m.RestoreVolume(t.Context(), "shop", "uploads", archiveName); err == nil || !strings.Contains(err.Error(), "not made by CommyPanel") {
		t.Errorf("got %v", err)
	}
	if exists(filepath.Join(vol, "evil.txt")) {
		t.Error("a changed archive was restored")
	}
}

func TestAnArchiveThatFailsHalfWayLeavesTheOldContent(t *testing.T) {
	// A bad entry after good ones: the scan refuses it first, and even without
	// the scan the unpacking happens in a sibling folder (see volumefs tests).
	m, _, vol := volumeSetup(t, []tarEntry{{name: "new.txt", body: "n"}, {name: "x", typ: tar.TypeSymlink, link: "/"}}, true)
	if _, err := m.RestoreVolume(t.Context(), "shop", "uploads", archiveName); err == nil {
		t.Fatal("want an error")
	}
	if !exists(filepath.Join(vol, "old.txt")) || exists(filepath.Join(vol, "new.txt")) {
		t.Error("the volume changed")
	}
}

func TestRestoreVolumeDoesNotFollowALinkedVolumeFolder(t *testing.T) {
	m, _, vol := volumeSetup(t, goodEntries, true)
	outside := t.TempDir()
	if err := os.RemoveAll(vol); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, vol); err != nil {
		t.Fatal(err)
	}
	if _, err := m.RestoreVolume(t.Context(), "shop", "uploads", archiveName); err == nil {
		t.Error("a linked volume folder was restored into")
	}
	if left, _ := os.ReadDir(outside); len(left) != 0 {
		t.Errorf("wrote outside: %v", left)
	}
}
