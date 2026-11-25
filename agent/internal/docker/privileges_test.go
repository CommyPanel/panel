package docker

import (
	"context"
	"errors"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

func inputError(t *testing.T, err error) string {
	t.Helper()
	var ie *validate.InputError
	if !errors.As(err, &ie) {
		t.Fatalf("want an input error, got %v", err)
	}
	return ie.Msg
}

func TestThePrivilegeAllowListsPerEngineAndScope(t *testing.T) {
	cases := []struct {
		engine, scope string
		ok            []string
		refused       []string
	}{
		{"mariadb", ScopeDatabase, []string{"SELECT", "CREATE TEMPORARY TABLES", "TRIGGER", "EVENT"}, []string{"CREATE USER", "SHOW DATABASES", "ALL PRIVILEGES", "ALL", "GRANT OPTION", "FILE", "SUPER", "PROCESS", "SHUTDOWN"}},
		{"mysql", ScopeDatabase, []string{"INSERT", "LOCK TABLES", "ALTER ROUTINE"}, []string{"CREATE USER", "USAGE", "RELOAD"}},
		{"mariadb", ScopeServer, []string{"SELECT", "CREATE USER", "SHOW DATABASES", "DROP"}, []string{"SUPER", "FILE", "ALL PRIVILEGES", "GRANT OPTION"}},
		{"mysql", ScopeServer, []string{"CREATE USER", "SHOW DATABASES"}, []string{"CREATEDB", "REPLICATION SLAVE"}},
		{"postgres", ScopeServer, []string{"CREATEDB", "CREATEROLE"}, []string{"SUPERUSER", "LOGIN", "REPLICATION", "BYPASSRLS", "SELECT", "CREATE"}},
	}
	for _, c := range cases {
		for _, p := range c.ok {
			if _, err := checkPrivileges(c.engine, c.scope, []string{p}, false); err != nil {
				t.Errorf("%s/%s %q must be allowed: %v", c.engine, c.scope, p, err)
			}
		}
		for _, p := range c.refused {
			if _, err := checkPrivileges(c.engine, c.scope, []string{p}, false); err == nil {
				t.Errorf("%s/%s %q must be refused", c.engine, c.scope, p)
			}
		}
	}
}

func TestPostgresPrivilegesAreForTheWholeServerOnly(t *testing.T) {
	if _, err := checkPrivileges("postgres", ScopeDatabase, nil, false); err == nil {
		t.Error("PostgreSQL has no database scope here")
	}
	if _, err := checkPrivileges("postgres", ScopeServer, []string{"CREATEDB"}, true); err == nil {
		t.Error("PostgreSQL has no grant option")
	}
}

func TestBadPrivilegeRequestsAreRefusedWithASentence(t *testing.T) {
	many := make([]string, maxPrivileges+1)
	for i := range many {
		many[i] = "SELECT"
	}
	cases := []struct {
		name        string
		engine      string
		scope       string
		privileges  []string
		grantOption bool
		contains    string
	}{
		{"unknown scope", "mariadb", "table", []string{"SELECT"}, false, "scope"},
		{"empty scope", "mariadb", "", nil, false, "scope"},
		{"lower case", "mariadb", ScopeDatabase, []string{"select"}, false, "not a privilege"},
		{"padded", "mariadb", ScopeDatabase, []string{" SELECT"}, false, "not a privilege"},
		{"injection", "mariadb", ScopeDatabase, []string{"SELECT ON *.* TO 'x'; DROP DATABASE x; --"}, false, "not a privilege"},
		{"duplicate", "mariadb", ScopeDatabase, []string{"SELECT", "INSERT", "SELECT"}, false, "twice"},
		{"too many", "mariadb", ScopeDatabase, many, false, "at most 40"},
		{"grant option on a database", "mariadb", ScopeDatabase, []string{"SELECT"}, true, "whole server"},
		{"grant option without anything to pass on", "mariadb", ScopeServer, nil, true, "at least one"},
		{"grant option on postgres", "postgres", ScopeServer, []string{"CREATEDB"}, true, "MySQL and MariaDB"},
		{"server privilege on a database", "mariadb", ScopeDatabase, []string{"CREATE USER"}, false, "not a privilege"},
	}
	for _, c := range cases {
		_, err := checkPrivileges(c.engine, c.scope, c.privileges, c.grantOption)
		if err == nil {
			t.Errorf("%s must be refused", c.name)
			continue
		}
		if msg := inputError(t, err); !strings.Contains(msg, c.contains) {
			t.Errorf("%s: %q does not say %q", c.name, msg, c.contains)
		}
	}
}

func TestThePrivilegeListKeepsTheOrderOfTheAllowList(t *testing.T) {
	l, err := checkPrivileges("mariadb", ScopeDatabase, []string{"TRIGGER", "SELECT", "ALTER"}, false)
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(l.privileges, ","); got != "SELECT,ALTER,TRIGGER" {
		t.Errorf("order = %s", got)
	}
}

func TestPrivilegeSQLIsFixedTextAroundCheckedNames(t *testing.T) {
	db := privilegeList{scope: ScopeDatabase, privileges: []string{"SELECT", "INSERT", "CREATE TEMPORARY TABLES"}}
	want := "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'shop_user'@'%';\n" +
		"GRANT SELECT, INSERT, CREATE TEMPORARY TABLES ON `shop\\_db`.* TO 'shop_user'@'%';\n" +
		"FLUSH PRIVILEGES;\n"
	for _, engine := range []string{"mariadb", "mysql"} {
		if got := privilegesSQL(engine, "shop_db", "shop_user", db); got != want {
			t.Errorf("%s database scope:\n%s", engine, got)
		}
	}

	none := privilegeList{scope: ScopeDatabase}
	if got := privilegesSQL("mariadb", "shop", "u", none); got != "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'u'@'%';\nFLUSH PRIVILEGES;\n" {
		t.Errorf("an empty list only takes everything back (the user can still log in): %q", got)
	}

	server := privilegeList{scope: ScopeServer, privileges: []string{"SELECT", "CREATE", "DROP", "CREATE USER", "SHOW DATABASES"}}
	if got := privilegesSQL("mariadb", "shop", "u", server); got != "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'u'@'%';\nGRANT SELECT, CREATE, DROP, CREATE USER, SHOW DATABASES ON *.* TO 'u'@'%';\nFLUSH PRIVILEGES;\n" {
		t.Errorf("server scope: %q", got)
	}
	server.grantOption = true
	if got := privilegesSQL("mysql", "shop", "u", server); !strings.Contains(got, "ON *.* TO 'u'@'%' WITH GRANT OPTION;\n") {
		t.Errorf("server scope with grant option: %q", got)
	}

	if got := privilegesSQL("postgres", "shop", "u", privilegeList{scope: ScopeServer, privileges: []string{"CREATEDB", "CREATEROLE"}}); got != "ALTER ROLE \"u\" CREATEDB CREATEROLE;\n" {
		t.Errorf("postgres both: %q", got)
	}
	if got := privilegesSQL("postgres", "shop", "u", privilegeList{scope: ScopeServer, privileges: []string{"CREATEDB"}}); got != "ALTER ROLE \"u\" CREATEDB NOCREATEROLE;\n" {
		t.Errorf("postgres createdb only: %q", got)
	}
	if got := privilegesSQL("postgres", "shop", "u", privilegeList{scope: ScopeServer}); got != "ALTER ROLE \"u\" NOCREATEDB NOCREATEROLE;\n" {
		t.Errorf("postgres none: %q", got)
	}
	if strings.Contains(privilegesSQL("postgres", "shop", "u", privilegeList{scope: ScopeServer}), "LOGIN") {
		t.Error("LOGIN is never changed")
	}
}

// answers makes the fake server say the database and its user exist (or not) and runs the rest as normal.
func answers(exists string) func(cmd []string, stdin string) (string, int) {
	return func(cmd []string, stdin string) (string, int) {
		if strings.HasPrefix(stdin, "SELECT") {
			return exists + "\n", 0
		}
		return "", 0
	}
}

func TestSetPrivilegesChecksTheUserThenRunsTheSQLThroughStdin(t *testing.T) {
	m, d, _ := setup(t)
	d.execScript = answers("1")

	err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "shop_user", ScopeDatabase, []string{"SELECT", "INSERT"}, false)
	if err != nil {
		t.Fatal(err)
	}

	d.mu.Lock()
	defer d.mu.Unlock()
	if len(d.execLog) != 2 {
		t.Fatalf("exec log = %v", d.execLog)
	}
	if !strings.Contains(d.execLog[0], "information_schema.schemata") || !strings.Contains(d.execLog[0], "User = 'shop_user'") {
		t.Errorf("first the database and the user are looked up: %s", d.execLog[0])
	}
	if !strings.Contains(d.execLog[1], "GRANT SELECT, INSERT ON `shop`.* TO 'shop_user'@'%'") || !strings.Contains(d.execLog[1], "FLUSH PRIVILEGES") {
		t.Errorf("then the privileges are set: %s", d.execLog[1])
	}
	for _, line := range d.execLog {
		if strings.HasPrefix(line, "mariadb") && !strings.HasPrefix(line, "mariadb -uroot") {
			t.Errorf("command = %s", line)
		}
		if strings.Contains(strings.SplitN(line, " | ", 2)[0], rootPw) {
			t.Errorf("the password is on the command line: %s", line)
		}
	}
}

func TestSetPrivilegesForPostgresChecksOwnershipAndChangesAttributes(t *testing.T) {
	m, d, _ := setup(t)
	d.execScript = answers("1")

	if err := m.SetDatabasePrivileges(context.Background(), target("postgres"), "shop", "shop_user", ScopeServer, []string{"CREATEDB"}, false); err != nil {
		t.Fatal(err)
	}

	d.mu.Lock()
	defer d.mu.Unlock()
	if !strings.HasPrefix(d.execLog[0], "psql -U postgres -X -q -v ON_ERROR_STOP=1 -At | SELECT count(*) FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba") {
		t.Errorf("ownership is checked with a plain answer: %s", d.execLog[0])
	}
	if !strings.HasSuffix(d.execLog[1], `ALTER ROLE "shop_user" CREATEDB NOCREATEROLE;`+"\n") {
		t.Errorf("attributes: %s", d.execLog[1])
	}
}

func TestSetPrivilegesRefusesAUserOrDatabaseThatIsNotThere(t *testing.T) {
	m, d, _ := setup(t)
	d.execScript = answers("0")

	err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "someone", ScopeDatabase, []string{"SELECT"}, false)
	if msg := inputError(t, err); !strings.Contains(msg, "does not exist") {
		t.Errorf("message = %q", msg)
	}

	d.mu.Lock()
	defer d.mu.Unlock()
	for _, line := range d.execLog {
		if strings.Contains(line, "GRANT") || strings.Contains(line, "REVOKE") {
			t.Errorf("nothing is changed for a user that is not there: %v", d.execLog)
		}
	}
}

func TestSetPrivilegesNeverReachesTheContainerForBadInput(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()
	tgt := target("mariadb")

	bad := []struct {
		db, user, scope string
		privileges      []string
		grant           bool
	}{
		{"shop", "root", ScopeDatabase, []string{"SELECT"}, false},
		{"shop", "admin", ScopeServer, []string{"SELECT"}, false},
		{"shop", "mysql", ScopeDatabase, nil, false},
		{"shop", "mysql_x", ScopeDatabase, nil, false},
		{"shop", "postgres", ScopeServer, nil, false},
		{"shop", "pg_monitor", ScopeServer, nil, false},
		{"shop", "u'; DROP USER root; --", ScopeDatabase, nil, false},
		{"shop", "u", ScopeDatabase, []string{"SELECT'; --"}, false},
		{"sh`op", "u", ScopeDatabase, nil, false},
		{"sh op", "u", ScopeDatabase, nil, false},
		{"mysql", "u", ScopeDatabase, nil, false},
		{"information_schema", "u", ScopeDatabase, nil, false},
		{"shop", "u", "everything", []string{"SELECT"}, false},
		{"shop", "u", ScopeDatabase, []string{"SELECT", "SELECT"}, false},
		{"shop", "u", ScopeDatabase, []string{"SELECT"}, true},
	}
	for _, b := range bad {
		if err := m.SetDatabasePrivileges(ctx, tgt, b.db, b.user, b.scope, b.privileges, b.grant); err == nil {
			t.Errorf("%+v must be refused", b)
		}
	}
	for _, other := range []DatabaseTarget{
		{Container: "Db Main", Engine: "mariadb", RootPassword: rootPw},
		{Container: "db-main", Engine: "mongo", RootPassword: rootPw},
		{Container: "app-1", Engine: "mariadb", RootPassword: rootPw},
	} {
		if err := m.SetDatabasePrivileges(ctx, other, "shop", "u", ScopeDatabase, nil, false); err == nil {
			t.Errorf("target %+v must be refused", other)
		}
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Fatalf("something reached the container: %v", d.requests)
		}
	}
}

func TestSetPrivilegesOnAContainerThatIsNotOurs(t *testing.T) {
	m, d, _ := setup(t)
	d.labels = map[string]string{"other": "x"}

	if err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "u", ScopeDatabase, []string{"SELECT"}, false); err == nil {
		t.Error("a container the agent did not create must be refused")
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Errorf("an exec was created: %v", d.requests)
		}
	}
}

func TestAPrivilegeFailureDoesNotRepeatTheRootPassword(t *testing.T) {
	m, d, _ := setup(t)
	d.execScript = func(cmd []string, stdin string) (string, int) {
		if strings.HasPrefix(stdin, "SELECT") {
			return "1\n", 0
		}
		return "ERROR 1045: access denied for root using password " + rootPw + "\n" + strings.Repeat("x", 500), 1
	}

	err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "u", ScopeDatabase, []string{"SELECT"}, false)

	msg := inputError(t, err)
	if strings.Contains(msg, rootPw) || !strings.Contains(msg, "***") || len(msg) > 400 {
		t.Errorf("message = %q", msg)
	}
}

func TestDryRunChangesNothing(t *testing.T) {
	m, d, _ := setup(t)
	m.cfg.DryRun = true

	if err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "u", ScopeDatabase, []string{"SELECT"}, false); err != nil {
		t.Fatal(err)
	}
	if err := m.SetDatabasePrivileges(context.Background(), target("mariadb"), "shop", "root", ScopeDatabase, nil, false); err == nil {
		t.Error("a dry run still refuses what a real run refuses")
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Errorf("a dry run reached the container: %v", d.requests)
		}
	}
}

// Whatever comes in, a privilege that is not on the engine's list never produces a result.
func FuzzCheckPrivileges(f *testing.F) {
	for _, seed := range []string{"SELECT", "select", "CREATE USER", "ALL PRIVILEGES", "SELECT, INSERT", "'; DROP", "", "CREATEDB", "GRANT OPTION", "\x00SELECT", "SELECT\n"} {
		f.Add(seed, uint8(0))
	}
	f.Fuzz(func(t *testing.T, name string, pick uint8) {
		engine := []string{"mariadb", "mysql", "postgres"}[int(pick)%3]
		scope := []string{ScopeDatabase, ScopeServer}[int(pick/3)%2]

		list, err := checkPrivileges(engine, scope, []string{name}, false)
		if err != nil {
			return
		}
		allowed := allowedPrivileges(engine, scope)
		if len(list.privileges) != 1 {
			t.Fatalf("one name gives one privilege: %v", list.privileges)
		}
		for _, a := range allowed {
			if a == list.privileges[0] && a == name {
				return
			}
		}
		t.Fatalf("%q was accepted for %s/%s but is not on the list", name, engine, scope)
	})
}
