package docker

import (
	"bytes"
	"os"
	"path/filepath"
	"testing"
)

func TestTheBuildContextDoesNotFollowALinkToAFileOutsideIt(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "secret")
	if err := os.WriteFile(outside, []byte("root only"), 0o600); err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "Dockerfile"), []byte("FROM scratch\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	// A link is sent as a link, never as the content of what it points at.
	if err := os.Symlink(outside, filepath.Join(dir, "link")); err != nil {
		t.Fatal(err)
	}

	var buf bytes.Buffer
	if err := writeTar(&buf, dir); err != nil {
		t.Fatalf("writeTar: %v", err)
	}
	if bytes.Contains(buf.Bytes(), []byte("root only")) {
		t.Error("the file a link points at ended up in the build context")
	}
}
