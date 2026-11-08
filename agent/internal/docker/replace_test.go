package docker

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const replaceRootPw = "abcdefghijklmnop1234"

func TestReplaceSQLIsExactlyTheFixedTextAroundTheNames(t *testing.T) {
	want := map[string]string{
		"postgres": `SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'shop_db' AND pid <> pg_backend_pid();
DROP DATABASE "shop_db" WITH (FORCE);
CREATE DATABASE "shop_db" OWNER "shop_user";
REVOKE ALL ON DATABASE "shop_db" FROM PUBLIC;
GRANT ALL PRIVILEGES ON DATABASE "shop_db" TO "shop_user";
`,
		"mysql": "DROP DATABASE `shop_db`;\n" +
			"CREATE DATABASE `shop_db` CHARACTER SET utf8mb4;\n" +
			"GRANT ALL PRIVILEGES ON `shop\\_db`.* TO 'shop_user'@'%';\n" +
			"FLUSH PRIVILEGES;\n",
	}
	want["mariadb"] = want["mysql"]
	for engine, sql := range want {
		if got := replaceSQL(engine, "shop_db", "shop_user"); got != sql {
			t.Errorf("%s:\n%s\nwant\n%s", engine, got, sql)
		}
	}
	if got := databaseExistsSQL("postgres", "shop"); got != "SELECT 1 FROM pg_database WHERE datname = 'shop';\n" {
		t.Error(got)
	}
	if got := databaseExistsSQL("mariadb", "shop"); got != "SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'shop';\n" {
		t.Error(got)
	}
	if got := userExistsSQL("postgres", "u1"); got != "SELECT 1 FROM pg_roles WHERE rolname = 'u1';\n" {
		t.Error(got)
	}
	if got := userExistsSQL("mysql", "u1"); got != "SELECT 1 FROM mysql.user WHERE User = 'u1' AND Host = '%';\n" {
		t.Error(got)
	}
}

// Whatever validate lets through, the SQL has no quote, backslash or semicolon
// of its own besides the fixed text.
func TestNamesThatCouldBreakOutOfTheSQLNeverReachIt(t *testing.T) {
	m, _ := newRestoreManager(t)
	target := DatabaseTarget{Container: "db-main", Engine: "postgres", RootPassword: replaceRootPw}
	for _, name := range []string{`a"; DROP DATABASE x; --`, "a'b", "a`b", "A", "1a", "a b", "", strings.Repeat("a", 32), `a\`, "a;b"} {
		if err := m.ReplaceDatabase(t.Context(), target, name, "shop", "main", "shop-20261103-040000.sql.gz"); err == nil {
			t.Errorf("database %q accepted", name)
		}
		if err := m.ReplaceDatabase(t.Context(), target, "shop", name, "main", "shop-20261103-040000.sql.gz"); err == nil {
			t.Errorf("user %q accepted", name)
		}
	}
	for _, file := range []string{"../x.sql.gz", "shop.sql.gz", "a/shop-20261103-040000.sql.gz", "shop-20261103-040000.sql.gz/", ""} {
		if err := m.ReplaceDatabase(t.Context(), target, "shop", "shop", "main", file); err == nil {
			t.Errorf("file %q accepted", file)
		}
	}
	bad := target
	bad.RootPassword = "short"
	if err := m.ReplaceDatabase(t.Context(), bad, "shop", "shop", "main", "shop-20261103-040000.sql.gz"); err == nil {
		t.Error("a bad administrator password was accepted")
	}
}

func TestDumpEngineIsReadFromTheToolsComment(t *testing.T) {
	for head, want := range map[string]string{
		"--\n-- PostgreSQL database dump\n--\n":                             "postgres",
		"-- MySQL dump 10.13  Distrib 8.4.0, for Linux (x86_64)\n":          "mysql",
		"/*M!999999\\- enable the sandbox mode */\n-- MariaDB dump 10.19\n": "mariadb",
		"DROP DATABASE everything;":                                         "",
		"":                                                                  "",
	} {
		if got := dumpEngine([]byte(head)); got != want {
			t.Errorf("%q = %q, want %q", head, got, want)
		}
	}
}

// replaceHarness answers the database server like a real one would, from state kept here.
type replaceHarness struct {
	dbExists, userExists bool
	loadExit             int
	dropExit             int
}

func (h *replaceHarness) script(cmd []string, stdin string) (string, int) {
	switch {
	case strings.Contains(stdin, "FROM pg_database") || strings.Contains(stdin, "information_schema.SCHEMATA"):
		if h.dbExists {
			return "1\n", 0
		}
		return "\n", 0
	case strings.Contains(stdin, "pg_roles") || strings.Contains(stdin, "mysql.user"):
		if h.userExists {
			return "1\n", 0
		}
		return "\n", 0
	case strings.Contains(stdin, "DROP DATABASE"):
		return "ERROR with " + replaceRootPw + "\n", h.dropExit
	default: // the load
		return "ERROR at line 3 for " + replaceRootPw + "\n", h.loadExit
	}
}

func gz(t *testing.T, content string) []byte {
	t.Helper()
	var b bytes.Buffer
	w := gzip.NewWriter(&b)
	_, _ = w.Write([]byte(content))
	_ = w.Close()
	return b.Bytes()
}

func replaceSetup(t *testing.T, engine, dump string, register bool) (*Manager, *fakeDaemon, *replaceHarness, DatabaseTarget) {
	t.Helper()
	m, d, dir := setup(t)
	h := &replaceHarness{dbExists: true, userExists: true}
	d.execScript = h.script
	if err := os.MkdirAll(filepath.Join(dir, "backups", "databases", "main"), 0o700); err != nil {
		t.Fatal(err)
	}
	data := gz(t, dump)
	if err := os.WriteFile(filepath.Join(dir, "backups", "databases", "main", "shop-20261103-040000.sql.gz"), data, 0o600); err != nil {
		t.Fatal(err)
	}
	if register {
		sum := sha256.Sum256(data)
		if err := m.RegisterDump(hex.EncodeToString(sum[:])); err != nil {
			t.Fatal(err)
		}
	}
	return m, d, h, DatabaseTarget{Container: "db-main", Engine: engine, RootPassword: replaceRootPw}
}

const (
	pgDump    = "--\n-- PostgreSQL database dump\n--\nCREATE TABLE t (id int);\n"
	mariaDump = "-- MariaDB dump 10.19\nCREATE TABLE t (id int);\n"
	mysqlDump = "-- MySQL dump 10.13\nCREATE TABLE t (id int);\n"
)

func replaceIt(m *Manager, tg DatabaseTarget) error {
	return m.ReplaceDatabase(context_(), tg, "shop", "shop", "main", "shop-20261103-040000.sql.gz")
}

func TestReplaceDropsCreatesAndLoadsForEachEngine(t *testing.T) {
	for engine, dump := range map[string]string{"postgres": pgDump, "mariadb": mariaDump, "mysql": mysqlDump} {
		m, d, _, tg := replaceSetup(t, engine, dump, true)
		if err := replaceIt(m, tg); err != nil {
			t.Fatalf("%s: %v", engine, err)
		}
		if len(d.execLog) != 4 {
			t.Fatalf("%s: %d commands: %v", engine, len(d.execLog), d.execLog)
		}
		replace := d.execLog[2]
		if !strings.HasSuffix(replace, " | "+replaceSQL(engine, "shop", "shop")) {
			t.Errorf("%s: third command was %q", engine, replace)
		}
		load := d.execLog[3]
		if !strings.Contains(load, "CREATE TABLE t") {
			t.Errorf("%s: the dump was not loaded: %q", engine, load)
		}
		if engine == "postgres" && !strings.HasPrefix(load, "psql -U shop -d shop") {
			t.Errorf("postgres must load as the database's own user: %q", load)
		}
		if engine != "postgres" && !strings.HasPrefix(load, engine+" -uroot") {
			t.Errorf("%s must load as root: %q", engine, load)
		}
		for _, line := range d.execLog {
			if strings.Contains(strings.SplitN(line, " | ", 2)[0], replaceRootPw) {
				t.Errorf("the password is in the arguments: %q", line)
			}
		}
	}
}

func TestReplaceRefusesBeforeDroppingAnything(t *testing.T) {
	dropped := func(d *fakeDaemon) bool {
		for _, l := range d.execLog {
			if strings.Contains(l, "DROP DATABASE") {
				return true
			}
		}
		return false
	}
	cases := map[string]struct {
		engine, dump string
		register     bool
		tweak        func(h *replaceHarness)
		want         string
	}{
		"wrong engine (MySQL dump, PostgreSQL server)": {"postgres", mysqlDump, true, nil, "made by mysql"},
		"wrong engine (PostgreSQL dump, MariaDB)":      {"mariadb", pgDump, true, nil, "made by postgres"},
		"mysql dump into mariadb":                      {"mariadb", mysqlDump, true, nil, "made by mysql"},
		"not a dump at all":                            {"postgres", "DROP DATABASE x;", true, nil, "does not say which"},
		"not made by the agent":                        {"postgres", pgDump, false, nil, "not made by CommyPanel"},
		"database missing":                             {"postgres", pgDump, true, func(h *replaceHarness) { h.dbExists = false }, "does not exist"},
		"user missing":                                 {"mariadb", mariaDump, true, func(h *replaceHarness) { h.userExists = false }, "user"},
	}
	for name, c := range cases {
		m, d, h, tg := replaceSetup(t, c.engine, c.dump, c.register)
		if c.tweak != nil {
			c.tweak(h)
		}
		err := replaceIt(m, tg)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: got %v, want %q", name, err, c.want)
		}
		if dropped(d) {
			t.Errorf("%s: the database was dropped", name)
		}
	}
}

func TestAFailedLoadSaysTheDatabaseIsEmptyAndHidesPasswords(t *testing.T) {
	m, _, h, tg := replaceSetup(t, "postgres", pgDump, true)
	h.loadExit = 3
	err := replaceIt(m, tg)
	if err == nil || !strings.Contains(err.Error(), "the database is now empty") {
		t.Fatalf("got %v", err)
	}
	if strings.Contains(err.Error(), replaceRootPw) {
		t.Errorf("the password leaked: %v", err)
	}

	m, _, h, tg = replaceSetup(t, "postgres", pgDump, true)
	h.dropExit = 1
	err = replaceIt(m, tg)
	if err == nil || !strings.Contains(err.Error(), "may now be missing or empty") || strings.Contains(err.Error(), replaceRootPw) {
		t.Fatalf("got %v", err)
	}
}

func TestReplaceNeedsARunningServerFromAnOfficialImage(t *testing.T) {
	m, d, _, tg := replaceSetup(t, "postgres", pgDump, true)
	d.labels = map[string]string{}
	if err := replaceIt(m, tg); err == nil || !strings.Contains(err.Error(), "not a database server") {
		t.Errorf("got %v", err)
	}
	if len(d.execLog) != 0 {
		t.Errorf("commands ran: %v", d.execLog)
	}
}

func context_() context.Context { return context.Background() }
