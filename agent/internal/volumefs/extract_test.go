package volumefs

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

type ent struct {
	name, body string
	typ        byte
	link       string
	uid, gid   int
	mode       int64
}

func tgz(t testing.TB, entries []ent) []byte {
	t.Helper()
	var b bytes.Buffer
	zw := gzip.NewWriter(&b)
	tw := tar.NewWriter(zw)
	for _, e := range entries {
		typ := e.typ
		if typ == 0 {
			typ = tar.TypeReg
		}
		mode := e.mode
		if mode == 0 {
			mode = 0o644
		}
		h := &tar.Header{Name: e.name, Typeflag: typ, Mode: mode, Linkname: e.link, Uid: e.uid, Gid: e.gid}
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

func TestCheckEntryTable(t *testing.T) {
	uid := 1000
	cases := []struct {
		h    tar.Header
		ok   bool
		name string
		dir  bool
		skip bool
	}{
		{tar.Header{Name: "a.txt", Typeflag: tar.TypeReg}, true, "a.txt", false, false},
		{tar.Header{Name: "./a/b.txt", Typeflag: tar.TypeReg}, true, "a/b.txt", false, false},
		{tar.Header{Name: "a/", Typeflag: tar.TypeDir}, true, "a", true, false},
		{tar.Header{Name: "./", Typeflag: tar.TypeDir}, true, "", true, true},
		{tar.Header{Name: "a/../b", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "../b", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "..", Typeflag: tar.TypeDir}, false, "", false, false},
		{tar.Header{Name: "/etc/x", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "a\\b", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "a\x00b", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "a/", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: ".", Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: strings.Repeat("a", 5000), Typeflag: tar.TypeReg}, false, "", false, false},
		{tar.Header{Name: "l", Typeflag: tar.TypeSymlink, Linkname: "/"}, false, "", false, false},
		{tar.Header{Name: "l", Typeflag: tar.TypeLink, Linkname: "a"}, false, "", false, false},
		{tar.Header{Name: "c", Typeflag: tar.TypeChar}, false, "", false, false},
		{tar.Header{Name: "b", Typeflag: tar.TypeBlock}, false, "", false, false},
		{tar.Header{Name: "f", Typeflag: tar.TypeFifo}, false, "", false, false},
		{tar.Header{Name: "s", Typeflag: tar.TypeGNUSparse}, false, "", false, false},
		{tar.Header{Name: "x", Typeflag: tar.TypeReg, Size: -1}, false, "", false, false},
		{tar.Header{Name: "x", Typeflag: tar.TypeReg, Uid: -1}, false, "", false, false},
		{tar.Header{Name: "x", Typeflag: tar.TypeReg, Gid: 1 << 33}, false, "", false, false},
		{tar.Header{Name: "x", Typeflag: tar.TypeReg, Uid: uid, Gid: uid}, true, "x", false, false},
	}
	for _, c := range cases {
		name, dir, skip, err := CheckEntry(&c.h)
		if (err == nil) != c.ok {
			t.Errorf("%q type %c: err = %v", c.h.Name, c.h.Typeflag, err)
			continue
		}
		if c.ok && (name != c.name || dir != c.dir || skip != c.skip) {
			t.Errorf("%q: got %q %v %v, want %q %v %v", c.h.Name, name, dir, skip, c.name, c.dir, c.skip)
		}
	}
}

func TestScanCountsAndRefusesEverythingExtractWould(t *testing.T) {
	good := tgz(t, []ent{{name: "d/", typ: tar.TypeDir}, {name: "d/a", body: "12345"}, {name: "b", body: "xy"}})
	sum, err := Scan(t.Context(), bytes.NewReader(good))
	if err != nil || sum.Files != 2 || sum.Bytes != 7 || sum.Dirs != 1 {
		t.Fatalf("scan = %+v, %v", sum, err)
	}
	for name, data := range map[string][]byte{
		"traversal": tgz(t, []ent{{name: "../x", body: "1"}}),
		"link":      tgz(t, []ent{{name: "x", typ: tar.TypeSymlink, link: "/etc"}}),
		"not gzip":  []byte("plain text"),
		"truncated": good[:len(good)/2],
	} {
		if _, err := Scan(t.Context(), bytes.NewReader(data)); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func newVolume(t *testing.T, mode os.FileMode) (volumes, vol string) {
	t.Helper()
	volumes = t.TempDir()
	vol = filepath.Join(volumes, "shop", "uploads")
	if err := os.MkdirAll(vol, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(vol, mode); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(vol, "old.txt"), []byte("old"), 0o644); err != nil {
		t.Fatal(err)
	}
	return
}

func writeArchive(t *testing.T, data []byte) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "a.tar.gz")
	if err := os.WriteFile(p, data, 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestReplaceSwapsTheContentKeepsFolderModeAndNumericOwners(t *testing.T) {
	volumes, vol := newVolume(t, 0o777)
	uid, gid := os.Getuid(), os.Getgid()
	arch := writeArchive(t, tgz(t, []ent{
		{name: "sub/", typ: tar.TypeDir, uid: uid, gid: gid, mode: 0o555},
		{name: "sub/a.txt", body: "alpha", uid: uid, gid: gid, mode: 0o640},
		{name: "run.sh", body: "#!/bin/sh", uid: uid, gid: gid, mode: 0o4755},
	}))

	sum, err := Replace(t.Context(), volumes, "shop", "uploads", arch, true)
	if err != nil {
		t.Fatal(err)
	}
	if sum.Files != 2 || sum.Bytes != 14 {
		t.Errorf("summary %+v", sum)
	}
	if info, _ := os.Stat(vol); info.Mode().Perm() != 0o777 {
		t.Errorf("folder mode %o", info.Mode().Perm())
	}
	for p, want := range map[string]os.FileMode{"sub": 0o555, "sub/a.txt": 0o640, "run.sh": 0o755} {
		info, err := os.Lstat(filepath.Join(vol, p))
		if err != nil {
			t.Fatal(err)
		}
		if info.Mode().Perm() != want || info.Mode()&(os.ModeSetuid|os.ModeSetgid|os.ModeSticky) != 0 {
			t.Errorf("%s: mode %v, want %o and no special bits", p, info.Mode(), want)
		}
		if st := info.Sys().(*syscall.Stat_t); int(st.Uid) != uid || int(st.Gid) != gid {
			t.Errorf("%s: owner %d:%d, want %d:%d", p, st.Uid, st.Gid, uid, gid)
		}
	}
	if _, err := os.Lstat(filepath.Join(vol, "old.txt")); err == nil {
		t.Error("old content remains")
	}
	if left, _ := filepath.Glob(filepath.Join(volumes, "shop", ".restore-*")); len(left) != 0 {
		t.Errorf("left behind %v", left)
	}
	os.Chmod(filepath.Join(vol, "sub"), 0o755) // so the temp dir can be cleaned up
}

func TestAFailedExtractionLeavesTheOldContentAndNoTempFolder(t *testing.T) {
	volumes, vol := newVolume(t, 0o750)
	// Good entries first, a duplicate last: this only fails while unpacking.
	arch := writeArchive(t, tgz(t, []ent{{name: "new.txt", body: "n"}, {name: "dup", body: "1"}, {name: "dup", body: "2"}}))
	if _, err := Replace(t.Context(), volumes, "shop", "uploads", arch, false); err == nil {
		t.Fatal("want an error")
	}
	if b, _ := os.ReadFile(filepath.Join(vol, "old.txt")); string(b) != "old" {
		t.Error("old content lost")
	}
	if _, err := os.Lstat(filepath.Join(vol, "new.txt")); err == nil {
		t.Error("new content leaked into the volume")
	}
	if left, _ := filepath.Glob(filepath.Join(volumes, "shop", ".restore-*")); len(left) != 0 {
		t.Errorf("left behind %v", left)
	}
	if info, _ := os.Stat(vol); info.Mode().Perm() != 0o750 {
		t.Errorf("mode %o", info.Mode().Perm())
	}
}

func TestExtractNeverWritesOutsideItsFolderEvenIfALinkAlreadyExists(t *testing.T) {
	outside := t.TempDir()
	dst := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(dst, "l")); err != nil {
		t.Fatal(err)
	}
	root, err := os.OpenRoot(dst)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	if _, err := Extract(t.Context(), root, bytes.NewReader(tgz(t, []ent{{name: "l/x", body: "x"}})), false); err == nil {
		t.Error("wrote through a link")
	}
	if left, _ := os.ReadDir(outside); len(left) != 0 {
		t.Errorf("wrote outside: %v", left)
	}
}

func TestReplaceValidatesNamesAndNeedsTheFolder(t *testing.T) {
	volumes, _ := newVolume(t, 0o755)
	arch := writeArchive(t, tgz(t, []ent{{name: "a", body: "1"}}))
	for _, c := range [][2]string{{"../shop", "uploads"}, {"shop", "../uploads"}, {"shop", "nope"}, {"nope", "uploads"}, {"shop", ""}} {
		if _, err := Replace(t.Context(), volumes, c[0], c[1], arch, false); err == nil {
			t.Errorf("%v accepted", c)
		}
	}
}

func FuzzScanAndCheckEntry(f *testing.F) {
	f.Add(tgz(f, []ent{{name: "a/", typ: tar.TypeDir}, {name: "a/b", body: "x"}}))
	f.Add(tgz(f, []ent{{name: "../x", body: "x"}}))
	f.Add(tgz(f, []ent{{name: "l", typ: tar.TypeSymlink, link: "/"}}))
	f.Add([]byte("not gzip"))
	f.Fuzz(func(t *testing.T, data []byte) {
		// Whatever the bytes are, Scan returns without panicking, and an
		// archive it accepts extracts into a scratch folder with nothing outside it.
		sum, err := Scan(t.Context(), bytes.NewReader(data))
		if err != nil {
			return
		}
		if sum.Bytes < 0 || sum.Files < 0 || sum.Bytes > MaxArchiveBytes {
			t.Fatalf("impossible summary %+v", sum)
		}
		base := t.TempDir()
		t.Cleanup(func() { // modes from the archive may forbid even deleting
			_ = filepath.WalkDir(base, func(p string, d os.DirEntry, err error) error {
				if d != nil && d.IsDir() {
					_ = os.Chmod(p, 0o700)
				}
				return nil
			})
		})
		dst := filepath.Join(base, "dst")
		if err := os.Mkdir(dst, 0o700); err != nil {
			t.Fatal(err)
		}
		root, err := os.OpenRoot(dst)
		if err != nil {
			t.Fatal(err)
		}
		defer root.Close()
		if _, err := Extract(t.Context(), root, bytes.NewReader(data), false); err != nil {
			return
		}
		entries, _ := os.ReadDir(base)
		if len(entries) != 1 {
			t.Fatalf("something was written outside the folder: %v", entries)
		}
	})
}
