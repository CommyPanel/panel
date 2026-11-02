package volumefs

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

func readArchive(t *testing.T, data []byte) map[string]string {
	t.Helper()
	zr, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(zr)
	out := map[string]string{}
	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		body, _ := io.ReadAll(tr)
		out[hdr.Name] = string(body)
	}
}

func TestArchiveHoldsFilesAndFoldersButNeverWhatALinkPointsAt(t *testing.T) {
	data := t.TempDir()
	vol := filepath.Join(data, "volumes", "shop", "uploads")
	if err := os.MkdirAll(filepath.Join(vol, "2026", "11"), 0o755); err != nil {
		t.Fatal(err)
	}
	must := func(err error) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.WriteFile(filepath.Join(vol, "a.txt"), []byte("alpha"), 0o644))
	must(os.WriteFile(filepath.Join(vol, "2026", "11", "b.txt"), []byte("beta"), 0o600))

	secret := filepath.Join(t.TempDir(), "secret")
	must(os.WriteFile(secret, []byte("root only"), 0o600))
	must(os.Symlink(secret, filepath.Join(vol, "link-to-secret")))
	must(os.Symlink(filepath.Dir(secret), filepath.Join(vol, "link-to-dir")))
	must(syscall.Mkfifo(filepath.Join(vol, "pipe"), 0o644))

	var buf bytes.Buffer
	if err := New(data).Archive(context.Background(), "shop", "uploads", &buf); err != nil {
		t.Fatalf("archive: %v", err)
	}
	got := readArchive(t, buf.Bytes())

	if got["a.txt"] != "alpha" || got["2026/11/b.txt"] != "beta" {
		t.Errorf("files missing: %v", got)
	}
	if _, ok := got["2026/"]; !ok {
		t.Errorf("a folder is missing: %v", got)
	}
	for name, body := range got {
		if body == "root only" || name == "link-to-secret" || name == "link-to-dir" || name == "pipe" {
			t.Errorf("%q must not be in the archive", name)
		}
	}
}

func TestArchiveKeepsOwnersAsNumbers(t *testing.T) {
	data := t.TempDir()
	vol := filepath.Join(data, "volumes", "shop", "uploads")
	if err := os.MkdirAll(vol, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(vol, "a.txt"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	var buf bytes.Buffer
	if err := New(data).Archive(context.Background(), "shop", "uploads", &buf); err != nil {
		t.Fatal(err)
	}
	zr, _ := gzip.NewReader(&buf)
	hdr, err := tar.NewReader(zr).Next()
	if err != nil {
		t.Fatal(err)
	}
	if hdr.Uid != os.Getuid() || hdr.Uname != "" {
		t.Errorf("uid = %d uname = %q, want the numeric owner %d", hdr.Uid, hdr.Uname, os.Getuid())
	}
}

func TestArchiveRefusesANameOutsideTheVolumes(t *testing.T) {
	b := New(t.TempDir())
	for _, c := range [][2]string{{"../x", "uploads"}, {"shop", "../x"}, {"shop", "missing"}} {
		if err := b.Archive(context.Background(), c[0], c[1], io.Discard); err == nil {
			t.Errorf("%v was accepted", c)
		}
	}
}

func TestArchiveStopsWhenTheRequestIsCancelled(t *testing.T) {
	data := t.TempDir()
	vol := filepath.Join(data, "volumes", "shop", "uploads")
	if err := os.MkdirAll(vol, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(vol, "a.txt"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := New(data).Archive(ctx, "shop", "uploads", io.Discard); err == nil {
		t.Error("a cancelled request still produced an archive")
	}
}
