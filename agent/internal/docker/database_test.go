package docker

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

const rootPw = "RootPassword12345678"
const userPw = "UserPassword12345678"

func target(engine string) DatabaseTarget {
	return DatabaseTarget{Container: "db-main", Engine: engine, RootPassword: rootPw}
}

func TestTheSQLIsFixedTextAroundCheckedNames(t *testing.T) {
	got := createSQL("mariadb", "shop", "shop_user", userPw)
	want := "CREATE DATABASE IF NOT EXISTS `shop` CHARACTER SET utf8mb4;\n" +
		"CREATE USER IF NOT EXISTS 'shop_user'@'%' IDENTIFIED BY '" + userPw + "';\n" +
		"ALTER USER 'shop_user'@'%' IDENTIFIED BY '" + userPw + "';\n" +
		"GRANT ALL PRIVILEGES ON `shop`.* TO 'shop_user'@'%';\n" +
		"FLUSH PRIVILEGES;\n"
	if got != want {
		t.Errorf("mariadb create:\n%s", got)
	}
	if mysql := createSQL("mysql", "shop", "shop_user", userPw); mysql != got {
		t.Error("mysql and mariadb use the same statements")
	}

	pg := createSQL("postgres", "shop", "shop_user", userPw)
	for _, part := range []string{`CREATE ROLE "shop_user" LOGIN PASSWORD '` + userPw + `'`, `CREATE DATABASE "shop" OWNER "shop_user"`, `\gexec`, `REVOKE ALL ON DATABASE "shop" FROM PUBLIC`, `GRANT ALL PRIVILEGES ON DATABASE "shop" TO "shop_user"`} {
		if !strings.Contains(pg, part) {
			t.Errorf("postgres create is missing %q:\n%s", part, pg)
		}
	}

	if got := dropSQL("postgres", "shop", "u"); got != "DROP DATABASE IF EXISTS \"shop\" WITH (FORCE);\nDROP ROLE IF EXISTS \"u\";\n" {
		t.Errorf("postgres drop: %q", got)
	}
	if got := dropSQL("mariadb", "shop", "u"); got != "DROP DATABASE IF EXISTS `shop`;\nDROP USER IF EXISTS 'u'@'%';\nFLUSH PRIVILEGES;\n" {
		t.Errorf("mariadb drop: %q", got)
	}
	if got := passwordSQL("postgres", "u", userPw); got != "ALTER ROLE \"u\" PASSWORD '"+userPw+"';\n" {
		t.Errorf("postgres password: %q", got)
	}
}

func TestEachEngineGetsItsOwnClientAndNoPasswordOnTheCommandLine(t *testing.T) {
	for engine, bin := range map[string]string{"mariadb": "mariadb", "mysql": "mysql", "postgres": "psql"} {
		cmd, env := adminCommand(target(engine))
		if cmd[0] != bin {
			t.Errorf("%s runs %v", engine, cmd)
		}
		for _, arg := range cmd {
			if strings.Contains(arg, rootPw) {
				t.Errorf("%s: the password is on the command line: %v", engine, cmd)
			}
		}
		if engine != "postgres" && (len(env) != 1 || env[0] != "MYSQL_PWD="+rootPw) {
			t.Errorf("%s: env = %v", engine, env)
		}
	}
}

func TestCreateDatabaseRunsTheSQLThroughStdin(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.CreateDatabase(context.Background(), target("mariadb"), "shop", "shop_user", userPw); err != nil {
		t.Fatal(err)
	}

	d.mu.Lock()
	defer d.mu.Unlock()
	if !strings.Contains(d.stdin, "CREATE DATABASE IF NOT EXISTS `shop`") || !strings.Contains(d.stdin, userPw) {
		t.Errorf("stdin = %q", d.stdin)
	}
	cmd, _ := d.execBody["Cmd"].([]any)
	if len(cmd) == 0 || cmd[0] != "mariadb" {
		t.Errorf("command = %v", d.execBody["Cmd"])
	}
	for _, a := range cmd {
		if s, _ := a.(string); strings.Contains(s, userPw) || strings.Contains(s, rootPw) {
			t.Errorf("a secret is in the command: %v", cmd)
		}
	}
	env, _ := d.execBody["Env"].([]any)
	if len(env) != 1 || env[0] != "MYSQL_PWD="+rootPw {
		t.Errorf("env = %v", env)
	}
	if d.execBody["Privileged"] != false || d.execBody["Tty"] != false {
		t.Errorf("exec options = %v", d.execBody)
	}
}

func TestBadNamesNeverReachTheContainer(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()

	bad := []struct{ db, user, pw string }{
		{"sh op", "u", userPw}, {"shop", "u;DROP", userPw}, {"shop", "u", "short"}, {"shop`; DROP DATABASE x; --", "u", userPw},
		{"mysql", "u", userPw}, {"postgres", "u", userPw}, {"information_schema", "u", userPw}, {"shop", "root", userPw},
		{"shop", "postgres", userPw}, {"shop", "pg_admin", userPw}, {"shop", "mysql_x", userPw}, {"shop", "u", userPw + "'"},
	}
	for _, b := range bad {
		if err := m.CreateDatabase(ctx, target("mariadb"), b.db, b.user, b.pw); err == nil {
			t.Errorf("%+v must be refused", b)
		}
	}
	for _, tgt := range []DatabaseTarget{
		{Container: "Db Main", Engine: "mariadb", RootPassword: rootPw},
		{Container: "db-main", Engine: "mongo", RootPassword: rootPw},
		{Container: "db-main", Engine: "mariadb", RootPassword: "short"},
		{Container: "../x", Engine: "mariadb", RootPassword: rootPw},
	} {
		if err := m.CreateDatabase(ctx, tgt, "shop", "u", userPw); err == nil {
			t.Errorf("target %+v must be refused", tgt)
		}
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Fatalf("something reached the container: %v", d.requests)
		}
	}
}

func TestADatabaseServerThatIsNotOurs(t *testing.T) {
	m, d, _ := setup(t)
	d.labels = map[string]string{"other": "x"}

	if err := m.CreateDatabase(context.Background(), target("mariadb"), "shop", "u", userPw); err == nil {
		t.Error("a container the agent did not create must be refused")
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Errorf("an exec was created: %v", d.requests)
		}
	}
}

func TestAFailureDoesNotRepeatAnySecret(t *testing.T) {
	m, d, _ := setup(t)
	d.execExit = 1
	d.execReply = "ERROR: syntax error at LINE 1: CREATE ROLE x PASSWORD '" + userPw + "' (root " + rootPw + ")\n" + strings.Repeat("x", 500)

	err := m.CreateDatabase(context.Background(), target("postgres"), "shop", "u", userPw)

	var ie *validate.InputError
	if !errors.As(err, &ie) {
		t.Fatalf("want an input error, got %v", err)
	}
	if strings.Contains(ie.Msg, userPw) || strings.Contains(ie.Msg, rootPw) {
		t.Errorf("a secret leaked: %s", ie.Msg)
	}
	if !strings.Contains(ie.Msg, "***") || len(ie.Msg) > 400 {
		t.Errorf("message = %q", ie.Msg)
	}
}

func TestReady(t *testing.T) {
	m, d, _ := setup(t)

	if ok, err := m.DatabaseReady(context.Background(), target("mariadb")); err != nil || !ok {
		t.Errorf("ready: %v %v", ok, err)
	}
	d.execExit = 1
	if ok, err := m.DatabaseReady(context.Background(), target("mariadb")); err != nil || ok {
		t.Errorf("a server that refuses the query is not ready: %v %v", ok, err)
	}
	d.execExit, d.execStatus = 0, 409
	if ok, err := m.DatabaseReady(context.Background(), target("mariadb")); err != nil || ok {
		t.Errorf("a stopped server is not ready, and that is not an error: %v %v", ok, err)
	}
}

func TestDropAndPassword(t *testing.T) {
	m, d, _ := setup(t)
	if err := m.DropDatabase(context.Background(), target("postgres"), "shop", "shop_user"); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(d.stdin, `DROP DATABASE IF EXISTS "shop" WITH (FORCE)`) {
		t.Errorf("stdin = %q", d.stdin)
	}
	if err := m.SetDatabasePassword(context.Background(), target("postgres"), "shop_user", userPw); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(d.stdin, `ALTER ROLE "shop_user" PASSWORD`) {
		t.Errorf("stdin = %q", d.stdin)
	}
	if err := m.SetDatabasePassword(context.Background(), target("postgres"), "root", userPw); err == nil {
		t.Error("the administrator's password is not changed through here")
	}
}

func TestPullImage(t *testing.T) {
	m, d, _ := setup(t)

	// Already there: nothing is downloaded.
	if err := m.PullImage(context.Background(), "postgres:17"); err != nil {
		t.Fatal(err)
	}
	if d.pulled != "" {
		t.Errorf("an image that is present was pulled: %q", d.pulled)
	}

	d.imageMissing = true
	d.pullReply = `{"status":"Pulling from library/postgres"}` + "\n" + `{"status":"Download complete"}` + "\n"
	if err := m.PullImage(context.Background(), "postgres:17"); err != nil {
		t.Fatal(err)
	}
	if d.pulled != "fromImage=postgres&tag=17" {
		t.Errorf("pulled %q", d.pulled)
	}

	d.pullReply = `{"status":"Pulling"}` + "\n" + `{"error":"manifest unknown"}` + "\n"
	err := m.PullImage(context.Background(), "postgres:99")
	var ie *validate.InputError
	if !errors.As(err, &ie) || !strings.Contains(ie.Msg, "manifest unknown") {
		t.Errorf("a failed pull should say why: %v", err)
	}

	for _, bad := range []string{"evil/x:1", "postgres:latest", "alpine:3", "postgres"} {
		if err := m.PullImage(context.Background(), bad); err == nil {
			t.Errorf("%q must be refused", bad)
		}
	}
}

func TestADatabaseContainerJoinsItsNetworksWithItsNames(t *testing.T) {
	m, d, dir := setup(t)
	spec := ContainerSpec{
		Name: "db-main", Image: "postgres:17", Network: "commypanel-db-main", Aliases: []string{"main"},
		Env:      map[string]string{"POSTGRES_PASSWORD": rootPw},
		Volumes:  []Volume{{Source: filepath.Join(dir, "databases", "main"), Target: "/var/lib/postgresql/data"}},
		Networks: []NetworkAttach{{Name: "commypanel-link-7", Aliases: []string{"main"}}},
	}

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}

	endpoints := d.created["NetworkingConfig"].(map[string]any)["EndpointsConfig"].(map[string]any)
	if aliases := endpoints["commypanel-db-main"].(map[string]any)["Aliases"].([]any); len(aliases) != 1 || aliases[0] != "main" {
		t.Errorf("aliases on the first network = %v", endpoints)
	}
	if len(d.connected) != 1 || d.connected[0] != "commypanel-link-7" {
		t.Errorf("connected to %v", d.connected)
	}
	if ep := d.connectBody["EndpointConfig"].(map[string]any)["Aliases"].([]any); ep[0] != "main" {
		t.Errorf("aliases on the link network = %v", d.connectBody)
	}
	if mode := mode(t, filepath.Join(dir, "databases", "main")); mode != 0o700 {
		t.Errorf("the data folder is %o, want 700 (the database image takes it over)", mode)
	}
	host := d.created["HostConfig"].(map[string]any)
	if host["Privileged"] != false || host["NetworkMode"] != "commypanel-db-main" {
		t.Errorf("host config = %v", host)
	}
	if _, published := host["PortBindings"]; published {
		t.Error("a database server must not publish a port")
	}
}

func TestRefusedContainerSpecs(t *testing.T) {
	m, _, dir := setup(t)
	base := func() ContainerSpec {
		return ContainerSpec{Name: "db-main", Image: "postgres:17", Network: "commypanel-db-main"}
	}
	cases := map[string]func(*ContainerSpec){
		"an image that is not ours or a database": func(s *ContainerSpec) { s.Image = "alpine:3" },
		"the latest tag":             func(s *ContainerSpec) { s.Image = "postgres:latest" },
		"host as an extra network":   func(s *ContainerSpec) { s.Networks = []NetworkAttach{{Name: "host"}} },
		"bridge as an extra network": func(s *ContainerSpec) { s.Networks = []NetworkAttach{{Name: "bridge"}} },
		"the same network twice":     func(s *ContainerSpec) { s.Networks = []NetworkAttach{{Name: "commypanel-a"}, {Name: "commypanel-a"}} },
		"the main network again":     func(s *ContainerSpec) { s.Networks = []NetworkAttach{{Name: "commypanel-db-main"}} },
		"nine extra networks": func(s *ContainerSpec) {
			for i := 0; i < 9; i++ {
				s.Networks = append(s.Networks, NetworkAttach{Name: "commypanel-n" + string(rune('a'+i))})
			}
		},
		"a bad alias":       func(s *ContainerSpec) { s.Aliases = []string{"Bad Alias"} },
		"too many aliases":  func(s *ContainerSpec) { s.Aliases = []string{"a1", "a2", "a3", "a4", "a5"} },
		"a bad alias there": func(s *ContainerSpec) { s.Networks = []NetworkAttach{{Name: "commypanel-x", Aliases: []string{"a b"}}} },
		"data outside the allowed folders": func(s *ContainerSpec) {
			s.Volumes = []Volume{{Source: filepath.Join(dir, "elsewhere"), Target: "/data"}}
		},
		"data climbing out of the databases folder": func(s *ContainerSpec) {
			s.Volumes = []Volume{{Source: filepath.Join(dir, "databases", "..", "secrets"), Target: "/data"}}
		},
	}
	for name, mutate := range cases {
		spec := base()
		mutate(&spec)
		if err := m.Run(context.Background(), spec); err == nil {
			t.Errorf("%s must be refused", name)
		}
	}
	if err := m.Run(context.Background(), base()); err != nil {
		t.Errorf("the plain database spec is refused: %v", err)
	}
}

func TestRemoveDatabaseData(t *testing.T) {
	m, d, dir := setup(t)
	data := filepath.Join(dir, "databases", "main")
	other := filepath.Join(dir, "databases", "other")
	for _, p := range []string{data, other} {
		os.MkdirAll(p, 0o700)
		os.WriteFile(filepath.Join(p, "file"), []byte("x"), 0o600)
	}
	keep := filepath.Join(dir, "volumes", "shop", "uploads")
	os.MkdirAll(keep, 0o755)

	d.mounts = []string{data}
	if err := m.RemoveDatabaseData(context.Background(), "main"); err == nil || !strings.Contains(err.Error(), "demo-1") {
		t.Errorf("data that is still mounted must be refused, naming the container: %v", err)
	}
	if _, err := os.Stat(data); err != nil {
		t.Error("mounted data was deleted")
	}

	d.mounts = nil
	if err := m.RemoveDatabaseData(context.Background(), "main"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(data); err == nil {
		t.Error("the data should be gone")
	}
	for _, p := range []string{other, keep} {
		if _, err := os.Stat(p); err != nil {
			t.Errorf("%s was touched", p)
		}
	}
	if err := m.RemoveDatabaseData(context.Background(), "main"); err != nil {
		t.Errorf("data that is already gone: %v", err)
	}
	for _, bad := range []string{"../volumes", "Main", "", "a/b"} {
		if err := m.RemoveDatabaseData(context.Background(), bad); err == nil {
			t.Errorf("%q must be refused", bad)
		}
	}
}

func TestEachEngineGetsItsOwnDumpTool(t *testing.T) {
	for engine, tool := range map[string]string{"postgres": "pg_dump", "mysql": "mysqldump", "mariadb": "mariadb-dump"} {
		cmd, env := dumpCommand(target(engine), "shop")
		if cmd[0] != tool || cmd[len(cmd)-1] != "shop" {
			t.Errorf("%s runs %v", engine, cmd)
		}
		for _, arg := range cmd {
			if strings.Contains(arg, rootPw) {
				t.Errorf("%s: the password is on the command line", engine)
			}
		}
		if engine != "postgres" && (len(env) != 1 || env[0] != "MYSQL_PWD="+rootPw) {
			t.Errorf("%s: env = %v", engine, env)
		}
	}
}

func TestDumpWritesTheOutputToATemporaryFile(t *testing.T) {
	m, d, dir := setup(t)
	d.execReply = "-- the dump\nCREATE TABLE t (id int);\n"

	path, err := m.DumpDatabase(context.Background(), target("postgres"), "shop")
	if err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(path)
	if string(got) != d.execReply {
		t.Errorf("file = %q", got)
	}
	if filepath.Dir(path) != filepath.Join(dir, "agent-state", "tmp") {
		t.Errorf("written to %s, want the agent's own tmp, outside what the panel can write", path)
	}
	if info, _ := os.Stat(path); info.Mode().Perm() != 0o600 {
		t.Errorf("temporary file mode %o, want 600", info.Mode().Perm())
	}
	d.mu.Lock()
	cmd, _ := d.execBody["Cmd"].([]any)
	d.mu.Unlock()
	if len(cmd) == 0 || cmd[0] != "pg_dump" {
		t.Errorf("command = %v", cmd)
	}
}

func TestAFailedDumpLeavesNoFileAndNoSecret(t *testing.T) {
	m, d, dir := setup(t)
	d.execExit = 1
	d.execReply = "partial output that must not be kept"
	d.execStderr = "pg_dump: error: connection failed for root " + rootPw

	path, err := m.DumpDatabase(context.Background(), target("postgres"), "shop")

	var ie *validate.InputError
	if !errors.As(err, &ie) || path != "" {
		t.Fatalf("want an input error and no path, got %q %v", path, err)
	}
	if strings.Contains(ie.Msg, rootPw) || !strings.Contains(ie.Msg, "connection failed") {
		t.Errorf("message = %q", ie.Msg)
	}
	left, _ := filepath.Glob(filepath.Join(dir, "tmp", "dump-*"))
	if len(left) != 0 {
		t.Errorf("a failed dump left %v", left)
	}
}

func TestDumpRefusesBadNamesBeforeTouchingTheContainer(t *testing.T) {
	m, d, _ := setup(t)

	for _, db := range []string{"sh op", "shop;", "postgres", "", "Shop"} {
		if _, err := m.DumpDatabase(context.Background(), target("postgres"), db); err == nil {
			t.Errorf("database %q must be refused", db)
		}
	}
	if _, err := m.DumpDatabase(context.Background(), DatabaseTarget{Container: "db-main", Engine: "mongo", RootPassword: rootPw}, "shop"); err == nil {
		t.Error("an unknown engine must be refused")
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Fatalf("something reached the container: %v", d.requests)
		}
	}
}

func TestDumpIsNotAvailableInDryRun(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, NewEngine("http://127.0.0.1:1", nil))
	if _, err := m.DumpDatabase(context.Background(), target("postgres"), "shop"); err == nil {
		t.Error("dry-run must not dump")
	}
}

func TestAMySQLGrantCoversOnlyTheNamedDatabase(t *testing.T) {
	for _, engine := range []string{"mysql", "mariadb"} {
		sql := createSQL(engine, "shop_db", "shop_db", "abcdefghijklmnop1234")
		if !strings.Contains(sql, "GRANT ALL PRIVILEGES ON `shop\\_db`.* TO") {
			t.Errorf("%s: the grant does not escape the underscore:\n%s", engine, sql)
		}
		if !strings.Contains(sql, "CREATE DATABASE IF NOT EXISTS `shop_db`") {
			t.Errorf("%s: the database itself must keep its real name:\n%s", engine, sql)
		}
	}
}

func TestACommandThatIsStillRunningIsNotAGoodDump(t *testing.T) {
	m, d, dir := setup(t)
	d.execReply = "-- cut off in the middle"
	d.execRunning = true
	defer func(n int) { exitPolls = n }(exitPolls)
	exitPolls = 2

	if path, err := m.DumpDatabase(context.Background(), target("postgres"), "shop"); err == nil {
		t.Fatalf("a dump whose command never finished was accepted: %s", path)
	}
	if left, _ := filepath.Glob(filepath.Join(dir, "agent-state", "tmp", "dump-*")); len(left) != 0 {
		t.Errorf("left behind: %v", left)
	}
	if err := m.runSQL(context.Background(), target("postgres"), "SELECT 1;"); err == nil {
		t.Error("SQL that never finished was reported as done")
	}
}

func TestDatabaseToolsRunOnlyInDatabaseServers(t *testing.T) {
	m, d, _ := setup(t)

	// An app that happens to be called db-shop is not a database server.
	d.image = "commypanel/db-shop:r1"
	if err := m.CreateDatabase(context.Background(), target("postgres"), "shop", "shop", userPw); err == nil {
		t.Error("the database client was run in an app's container")
	}
	if ready, _ := m.DatabaseReady(context.Background(), target("postgres")); ready {
		t.Error("an app's container counted as a ready database server")
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Errorf("something was executed in the app's container: %s", r)
		}
	}

	// And a container that is not named like one is refused before Docker is asked.
	d.image = ""
	other := target("postgres")
	other.Container = "shop-1"
	if err := m.DropDatabase(context.Background(), other, "shop", "shop"); err == nil {
		t.Error("a container not named db-... was accepted as a database server")
	}
}

func TestDatabaseDataIsNotRemovedWhenDockerCannotBeAskedWhatStillUsesIt(t *testing.T) {
	dir := t.TempDir()
	data := filepath.Join(dir, "databases", "main")
	if err := os.MkdirAll(data, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(data, "PG_VERSION"), []byte("17"), 0o600); err != nil {
		t.Fatal(err)
	}
	// Nothing listens on this socket: Docker is down.
	m := NewManager(Config{DataDir: dir}, NewUnixEngine(filepath.Join(dir, "no-docker.sock")))

	if err := m.RemoveDatabaseData(context.Background(), "main"); err == nil {
		t.Error("the data of a database server was removed without knowing whether it is in use")
	}
	if _, err := os.Stat(filepath.Join(data, "PG_VERSION")); err != nil {
		t.Errorf("the data is gone: %v", err)
	}
}
