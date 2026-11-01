package docker

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func mode(t *testing.T, path string) os.FileMode {
	t.Helper()
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	return info.Mode().Perm()
}

func TestRunCreatesVolumeFolderOpenToTheAppButPrivateAbove(t *testing.T) {
	old := syscallUmask(0o022)
	defer syscallUmask(old)

	m, _, dir := setup(t)
	spec := baseSpec()
	source := filepath.Join(dir, "volumes", "demo", "uploads")
	spec.Volumes = []Volume{{Source: source, Target: "/data"}}

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}

	if got := mode(t, source); got != 0o777 {
		t.Errorf("volume folder is %o, want 777 (the app's user is unknown)", got)
	}
	for _, parent := range []string{filepath.Join(dir, "volumes", "demo"), filepath.Join(dir, "volumes")} {
		if got := mode(t, parent); got != 0o700 {
			t.Errorf("%s is %o, want 700 so nobody else can reach the volume", parent, got)
		}
	}
}

func TestRunLeavesAnExistingVolumeFolderAlone(t *testing.T) {
	m, _, dir := setup(t)
	source := filepath.Join(dir, "volumes", "demo", "data")
	if err := os.MkdirAll(source, 0o750); err != nil {
		t.Fatal(err)
	}
	spec := baseSpec()
	spec.Volumes = []Volume{{Source: source, Target: "/data"}}

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if got := mode(t, source); got != 0o750 {
		t.Errorf("existing folder changed to %o", got)
	}
}

func TestVolumeTargetsThatWouldCoverTheContainerAreRefused(t *testing.T) {
	m, _, dir := setup(t)

	for _, target := range []string{"/", "/proc", "/sys/kernel", "/dev", "/dev/shm", "/data/../x", "relative"} {
		spec := baseSpec()
		spec.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "demo", "x"), Target: target}}
		if err := m.Run(context.Background(), spec); err == nil {
			t.Errorf("target %q must be refused", target)
		}
	}

	spec := baseSpec()
	spec.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "demo", "x"), Target: "/app/data"}}
	if err := m.Run(context.Background(), spec); err != nil {
		t.Errorf("a normal target is refused: %v", err)
	}
}

func TestRemoveVolumes(t *testing.T) {
	m, _, dir := setup(t)
	volumes := filepath.Join(dir, "volumes")
	for _, p := range []string{"shop/uploads", "shop/cache", "blog/data"} {
		if err := os.MkdirAll(filepath.Join(volumes, p), 0o755); err != nil {
			t.Fatal(err)
		}
		os.WriteFile(filepath.Join(volumes, p, "file"), []byte("x"), 0o644)
	}
	exists := func(p string) bool { _, err := os.Stat(filepath.Join(volumes, p)); return err == nil }

	if err := m.RemoveVolumes(context.Background(), "shop", "uploads"); err != nil {
		t.Fatal(err)
	}
	if exists("shop/uploads") || !exists("shop/cache") || !exists("blog/data") {
		t.Error("only shop/uploads should be gone")
	}

	if err := m.RemoveVolumes(context.Background(), "shop", ""); err != nil {
		t.Fatal(err)
	}
	if exists("shop") || !exists("blog/data") {
		t.Error("the whole shop folder should be gone and blog kept")
	}

	if err := m.RemoveVolumes(context.Background(), "shop", ""); err != nil {
		t.Errorf("removing what is already gone: %v", err)
	}
}

func TestRemoveVolumesRefusesBadNamesAndNeverFollowsALink(t *testing.T) {
	m, _, dir := setup(t)
	volumes := filepath.Join(dir, "volumes")
	outside := filepath.Join(dir, "precious")
	os.MkdirAll(outside, 0o755)
	os.WriteFile(filepath.Join(outside, "keep"), []byte("x"), 0o644)
	os.MkdirAll(filepath.Join(volumes, "shop"), 0o755)

	for _, c := range [][2]string{{"../precious", ""}, {"shop", "../../precious"}, {"shop", "a/b"}, {"Shop", ""}, {"", ""}, {"shop", "UP"}} {
		if err := m.RemoveVolumes(context.Background(), c[0], c[1]); err == nil {
			t.Errorf("%q/%q must be refused", c[0], c[1])
		}
	}

	// A link inside the volumes directory that points out of it.
	if err := os.Symlink(outside, filepath.Join(volumes, "shop", "link")); err != nil {
		t.Fatal(err)
	}
	if err := m.RemoveVolumes(context.Background(), "shop", "link"); err == nil {
		t.Error("a volume that links outside must be refused")
	}
	if _, err := os.Stat(filepath.Join(outside, "keep")); err != nil {
		t.Error("data outside the volumes directory was touched")
	}
}

func TestRemoveVolumesRefusesWhileAContainerStillMountsIt(t *testing.T) {
	m, d, dir := setup(t)
	volumes := filepath.Join(dir, "volumes")
	for _, p := range []string{"shop/uploads", "shop/cache"} {
		os.MkdirAll(filepath.Join(volumes, p), 0o755)
	}
	d.mounts = []string{filepath.Join(volumes, "shop", "uploads")}

	err := m.RemoveVolumes(context.Background(), "shop", "uploads")
	if err == nil || !strings.Contains(err.Error(), "demo-1") {
		t.Fatalf("a mounted volume must be refused, naming the container: %v", err)
	}
	if _, statErr := os.Stat(filepath.Join(volumes, "shop", "uploads")); statErr != nil {
		t.Error("the mounted volume was deleted")
	}
	// Removing all of an app's volumes is refused too while one is mounted.
	if err := m.RemoveVolumes(context.Background(), "shop", ""); err == nil {
		t.Error("removing everything must be refused while one volume is mounted")
	}
	// A volume nothing mounts can still go.
	if err := m.RemoveVolumes(context.Background(), "shop", "cache"); err != nil {
		t.Errorf("an unmounted volume: %v", err)
	}
}

func TestRemoveVolumesProceedsWhenDockerIsDown(t *testing.T) {
	dir := t.TempDir()
	m := NewManager(Config{DataDir: dir}, NewEngine("http://127.0.0.1:1", nil))
	target := filepath.Join(dir, "volumes", "shop", "data")
	os.MkdirAll(target, 0o755)

	if err := m.RemoveVolumes(context.Background(), "shop", "data"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(target); err == nil {
		t.Error("the volume should be gone")
	}
}
