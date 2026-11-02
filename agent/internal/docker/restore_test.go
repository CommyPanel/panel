package docker

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newRestoreManager(t *testing.T) (*Manager, string) {
	t.Helper()
	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, "backups", "databases", "main"), 0o700); err != nil {
		t.Fatal(err)
	}
	return NewManager(Config{DataDir: dir}, nil), dir
}

func writeDump(t *testing.T, dir, content string) string {
	t.Helper()
	name := "shop-20261103-040000.sql.gz"
	if err := os.WriteFile(filepath.Join(dir, "backups", "databases", "main", name), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return name
}

func TestACopiedDumpIsRefusedUnlessTheAgentMadeIt(t *testing.T) {
	m, dir := newRestoreManager(t)
	name := writeDump(t, dir, "DROP DATABASE everything;")

	if _, err := m.copyDump("main", name); err == nil || !strings.Contains(err.Error(), "not made by CommyPanel") {
		t.Fatalf("an unregistered file must be refused, got %v", err)
	}
	if left, _ := filepath.Glob(filepath.Join(dir, "agent-state", "tmp", "restore-*")); len(left) != 0 {
		t.Errorf("a refused file left a copy behind: %v", left)
	}

	sum := sha256.Sum256([]byte("DROP DATABASE everything;"))
	if err := m.RegisterDump(hex.EncodeToString(sum[:])); err != nil {
		t.Fatal(err)
	}
	path, err := m.copyDump("main", name)
	if err != nil {
		t.Fatalf("a registered file must be accepted: %v", err)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
		t.Errorf("the private copy is %v, want 0600", info.Mode().Perm())
	}
}

func TestAChangedDumpIsNoLongerRegistered(t *testing.T) {
	m, dir := newRestoreManager(t)
	name := writeDump(t, dir, "original")
	sum := sha256.Sum256([]byte("original"))
	if err := m.RegisterDump(hex.EncodeToString(sum[:])); err != nil {
		t.Fatal(err)
	}

	writeDump(t, dir, "original plus something evil")

	if _, err := m.copyDump("main", name); err == nil {
		t.Fatal("a file changed after it was dumped must be refused")
	}
}

func TestADumpCannotBeReadThroughALinkOrOutsideTheBackupFolder(t *testing.T) {
	m, dir := newRestoreManager(t)
	secret := filepath.Join(dir, "secret.sql.gz")
	if err := os.WriteFile(secret, []byte("secret"), 0o600); err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte("secret"))
	_ = m.RegisterDump(hex.EncodeToString(sum[:]))

	link := filepath.Join(dir, "backups", "databases", "main", "shop-20261103-040000.sql.gz")
	if err := os.Symlink(secret, link); err != nil {
		t.Fatal(err)
	}

	if _, err := m.copyDump("main", "shop-20261103-040000.sql.gz"); err == nil {
		t.Error("a symbolic link must not be followed")
	}
	if _, err := m.copyDump("../..", "shop-20261103-040000.sql.gz"); err == nil {
		t.Error("a path outside the backup folder must be refused")
	}
}

func TestRestoreChecksItsInputsBeforeTouchingAnything(t *testing.T) {
	m, _ := newRestoreManager(t)
	target := DatabaseTarget{Container: "db-main", Engine: "postgres", RootPassword: "abcdefghijklmnop1234"}

	for name, call := range map[string]func() error{
		"file name": func() error { return m.RestoreDatabase(t.Context(), target, "shop2", "shop2", "main", "../x.sql.gz") },
		"database name": func() error {
			return m.RestoreDatabase(t.Context(), target, "postgres", "shop2", "main", "shop-20261103-040000.sql.gz")
		},
		"user name": func() error {
			return m.RestoreDatabase(t.Context(), target, "shop2", "root", "main", "shop-20261103-040000.sql.gz")
		},
		"server name": func() error {
			return m.RestoreDatabase(t.Context(), target, "shop2", "shop2", "Bad Name", "shop-20261103-040000.sql.gz")
		},
	} {
		if err := call(); err == nil {
			t.Errorf("%s: want an error", name)
		}
	}
}

func TestRestoreCommandsRunAsTheRightUserWithoutSecretsInTheArguments(t *testing.T) {
	cmd, env := restoreCommand(DatabaseTarget{Engine: "postgres", RootPassword: "abcdefghijklmnop1234"}, "shop2", "shop2")
	if strings.Join(cmd, " ") != "psql -U shop2 -d shop2 -X -q -v ON_ERROR_STOP=1 --single-transaction" || env != nil {
		t.Errorf("postgres: %v %v", cmd, env)
	}
	for _, engine := range []string{"mysql", "mariadb"} {
		cmd, env := restoreCommand(DatabaseTarget{Engine: engine, RootPassword: "abcdefghijklmnop1234"}, "shop2", "shop2")
		if strings.Contains(strings.Join(cmd, " "), "abcdefghijklmnop1234") || len(env) != 1 || env[0] != "MYSQL_PWD=abcdefghijklmnop1234" {
			t.Errorf("%s: %v %v", engine, cmd, env)
		}
	}
}

func TestTheStateDirectoryMustBePrivate(t *testing.T) {
	dir := t.TempDir()
	state := filepath.Join(dir, "state")
	m := NewManager(Config{DataDir: dir, StateDir: state}, nil)

	if _, err := m.stateDir("tmp"); err != nil {
		t.Fatalf("a fresh state directory: %v", err)
	}
	if info, _ := os.Stat(state); info.Mode().Perm()&0o022 != 0 {
		t.Errorf("state dir is %v, others must not be able to write to it", info.Mode().Perm())
	}

	// A folder others can write to is not trusted, however it came about.
	if err := os.Chmod(state, 0o777); err != nil {
		t.Fatal(err)
	}
	if _, err := m.stateDir("tmp"); err == nil {
		t.Error("a world-writable state directory was accepted")
	}
	if err := os.Chmod(state, 0o700); err != nil {
		t.Fatal(err)
	}

	// And a link in its place is not followed.
	other := filepath.Join(dir, "other")
	if err := os.Mkdir(other, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(filepath.Join(state, "tmp")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(other, filepath.Join(state, "tmp")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.stateDir("tmp"); err == nil {
		t.Error("a link in place of a state folder was accepted")
	}
}

func TestCleanScratchRemovesWhatACrashLeftBehind(t *testing.T) {
	m, dir := newRestoreManager(t)
	tmp := filepath.Join(dir, "agent-state", "tmp")
	if err := os.MkdirAll(tmp, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(tmp, "dump-123.sql"), []byte("a whole database"), 0o600); err != nil {
		t.Fatal(err)
	}

	m.CleanScratch()

	if left, _ := os.ReadDir(tmp); len(left) != 0 {
		t.Errorf("left behind: %v", left)
	}
}
