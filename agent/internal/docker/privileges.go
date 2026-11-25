package docker

import (
	"context"
	"fmt"
	"log/slog"
	"strings"

	"commypanel/agent/internal/validate"
)

// Privileges of a database user that the panel may change. Only names from these fixed lists ever reach
// SQL, so a privilege can never carry anything else with it; the lists are in the order the statements
// are written.
const (
	ScopeDatabase = "database"
	ScopeServer   = "server"

	// maxPrivileges bounds a request before any of it is looked at.
	maxPrivileges = 40
)

var mysqlDatabasePrivileges = []string{
	"SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "REFERENCES", "INDEX", "ALTER",
	"CREATE TEMPORARY TABLES", "LOCK TABLES", "EXECUTE", "CREATE VIEW", "SHOW VIEW",
	"CREATE ROUTINE", "ALTER ROUTINE", "EVENT", "TRIGGER",
}

// Only meaningful on the whole server.
var mysqlServerOnlyPrivileges = []string{"CREATE USER", "SHOW DATABASES"}

var postgresServerPrivileges = []string{"CREATEDB", "CREATEROLE"}

// privilegeList is a checked request: which privileges, in the order of the allow-list.
type privilegeList struct {
	scope       string
	privileges  []string
	grantOption bool
}

// allowedPrivileges is the allow-list for one engine and scope, or nil when that scope does not exist there.
func allowedPrivileges(engine, scope string) []string {
	switch {
	case engine == "postgres" && scope == ScopeServer:
		return postgresServerPrivileges
	case engine == "postgres":
		return nil
	case scope == ScopeDatabase:
		return mysqlDatabasePrivileges
	case scope == ScopeServer:
		return append(append([]string{}, mysqlDatabasePrivileges...), mysqlServerOnlyPrivileges...)
	}
	return nil
}

// checkPrivileges validates a request against the allow-list of the engine and scope. Names are exact
// (upper case, as written in the lists): anything else is refused, with a sentence for the person, and
// nothing is executed. Duplicates are refused too, so a list always means what it says.
func checkPrivileges(engine, scope string, privileges []string, grantOption bool) (privilegeList, error) {
	if scope != ScopeDatabase && scope != ScopeServer {
		return privilegeList{}, &validate.InputError{Msg: `the scope must be "database" or "server"`}
	}
	allowed := allowedPrivileges(engine, scope)
	if allowed == nil {
		return privilegeList{}, &validate.InputError{Msg: "PostgreSQL privileges are changed for the whole server only"}
	}
	if len(privileges) > maxPrivileges {
		return privilegeList{}, &validate.InputError{Msg: fmt.Sprintf("at most %d privileges can be given", maxPrivileges)}
	}
	if grantOption && (engine == "postgres" || scope != ScopeServer) {
		return privilegeList{}, &validate.InputError{Msg: "the right to pass privileges on is only for MySQL and MariaDB, on the whole server"}
	}

	want := map[string]bool{}
	for _, p := range privileges {
		known := false
		for _, a := range allowed {
			if p == a {
				known = true
				break
			}
		}
		if !known {
			return privilegeList{}, &validate.InputError{Msg: fmt.Sprintf("%q is not a privilege that can be set here", truncate(p, 40))}
		}
		if want[p] {
			return privilegeList{}, &validate.InputError{Msg: fmt.Sprintf("the privilege %s is listed twice", p)}
		}
		want[p] = true
	}
	if grantOption && len(want) == 0 {
		return privilegeList{}, &validate.InputError{Msg: "the right to pass privileges on needs at least one privilege to pass on"}
	}

	list := privilegeList{scope: scope, grantOption: grantOption}
	for _, a := range allowed {
		if want[a] {
			list.privileges = append(list.privileges, a)
		}
	}
	return list, nil
}

func truncate(s string, n int) string {
	if len(s) > n {
		return s[:n] + "..."
	}
	return s
}

// privilegesSQL is the fixed statements that make a user's privileges exactly the list. Every name in it
// went through validate.DatabaseIdent or the allow-lists above.
//
// MySQL and MariaDB: `REVOKE ALL PRIVILEGES, GRANT OPTION FROM user` takes back every privilege the user
// has at every level (and never fails for a user that has none, which a revoke of one level can), then the
// list is granted on the database or on the server. The right to create and drop databases, to see all of
// them, to create users and to pass privileges on are server-wide, so they are only given on the server.
// PostgreSQL: only CREATEDB and CREATEROLE, as role attributes; LOGIN and the ownership of the database
// are never changed.
func privilegesSQL(engine, db, user string, l privilegeList) string {
	if engine == "postgres" {
		attr := func(name string) string {
			for _, p := range l.privileges {
				if p == name {
					return name
				}
			}
			return "NO" + name
		}
		return fmt.Sprintf("ALTER ROLE \"%s\" %s %s;\n", user, attr("CREATEDB"), attr("CREATEROLE"))
	}

	var b strings.Builder
	fmt.Fprintf(&b, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '%s'@'%%';\n", user)
	if len(l.privileges) > 0 {
		target := "`" + grantPattern(db) + "`.*"
		if l.scope == ScopeServer {
			target = "*.*"
		}
		fmt.Fprintf(&b, "GRANT %s ON %s TO '%s'@'%%'", strings.Join(l.privileges, ", "), target, user)
		if l.grantOption {
			b.WriteString(" WITH GRANT OPTION")
		}
		b.WriteString(";\n")
	}
	b.WriteString("FLUSH PRIVILEGES;\n")
	return b.String()
}

// existsSQL asks whether the database and the user are there, and for PostgreSQL whether the user owns
// the database: privileges are only changed for the user of that database. It answers one line with 1 or 0.
func existsSQL(engine, db, user string) string {
	if engine == "postgres" {
		return fmt.Sprintf("SELECT count(*) FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba WHERE d.datname = '%s' AND r.rolname = '%s';\n", db, user)
	}
	return fmt.Sprintf("SELECT (SELECT count(*) FROM information_schema.schemata WHERE schema_name = '%s') * (SELECT count(*) FROM mysql.user WHERE User = '%s' AND Host = '%%');\n", db, user)
}

// SetDatabasePrivileges makes the privileges of a database user exactly `privileges` (an empty list leaves a
// user that can only log in). The administrator account and the names the engines keep are refused, and so
// is a user that does not exist, or for PostgreSQL does not own the database.
func (m *Manager) SetDatabasePrivileges(ctx context.Context, t DatabaseTarget, db, user, scope string, privileges []string, grantOption bool) error {
	if err := t.validate(); err != nil {
		return err
	}
	if err := checkDatabaseName(db); err != nil {
		return err
	}
	if err := checkDatabaseUser(user); err != nil {
		return err
	}
	list, err := checkPrivileges(t.Engine, scope, privileges, grantOption)
	if err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would change database privileges", "container", t.Container, "user", user, "scope", scope, "privileges", list.privileges)
		return nil
	}

	if err := m.checkTarget(ctx, t); err != nil {
		return err
	}
	cmd, env := adminCommand(t)
	if t.Engine == "postgres" {
		// Plain, unaligned output without headers, so the answer is just the number.
		cmd = append(cmd, "-At")
	}
	res, err := m.execIn(ctx, t.Container, cmd, env, existsSQL(t.Engine, db, user))
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return &validate.InputError{Msg: "the database server refused it: " + redact(res.Output, t.RootPassword)}
	}
	if strings.TrimSpace(res.Output) != "1" {
		return &validate.InputError{Msg: "that database or its user does not exist on this server"}
	}

	return m.runSQL(ctx, t, privilegesSQL(t.Engine, db, user, list), t.RootPassword)
}
