package docker

import (
	"bufio"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"os"
	"strings"

	"commypanel/agent/internal/validate"
)

// The SQL below is fixed text around names that went through
// validate.DatabaseIdent (letters, digits and underscores), so none of them can
// break out of the quotes around it.

// replaceSQL empties a database by dropping and creating it again, and gives
// its user back what it had: all rights on that one database. PostgreSQL ends
// the other sessions first (and WITH (FORCE) makes sure), the new database is
// owned by the user like the one the panel made first.
func replaceSQL(engine, db, user string) string {
	if engine == "postgres" {
		return fmt.Sprintf(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%[1]s' AND pid <> pg_backend_pid();
DROP DATABASE "%[1]s" WITH (FORCE);
CREATE DATABASE "%[1]s" OWNER "%[2]s";
REVOKE ALL ON DATABASE "%[1]s" FROM PUBLIC;
GRANT ALL PRIVILEGES ON DATABASE "%[1]s" TO "%[2]s";
`, db, user)
	}
	return fmt.Sprintf("DROP DATABASE `%[1]s`;\n"+
		"CREATE DATABASE `%[1]s` CHARACTER SET utf8mb4;\n"+
		"GRANT ALL PRIVILEGES ON `%[2]s`.* TO '%[3]s'@'%%';\n"+
		"FLUSH PRIVILEGES;\n", db, grantPattern(db), user)
}

func databaseExistsSQL(engine, db string) string {
	if engine == "postgres" {
		return fmt.Sprintf("SELECT 1 FROM pg_database WHERE datname = '%s';\n", db)
	}
	return fmt.Sprintf("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '%s';\n", db)
}

func userExistsSQL(engine, user string) string {
	if engine == "postgres" {
		return fmt.Sprintf("SELECT 1 FROM pg_roles WHERE rolname = '%s';\n", user)
	}
	return fmt.Sprintf("SELECT 1 FROM mysql.user WHERE User = '%s' AND Host = '%%';\n", user)
}

// queryCommand is adminCommand with bare output, so a yes/no query answers "1" or nothing.
func queryCommand(t DatabaseTarget) (cmd, env []string) {
	if t.Engine == "postgres" {
		return []string{"psql", "-U", "postgres", "-X", "-q", "-t", "-A", "-v", "ON_ERROR_STOP=1"}, nil
	}
	return adminCommand(t)
}

func (m *Manager) exists(ctx context.Context, t DatabaseTarget, sql string) (bool, error) {
	cmd, env := queryCommand(t)
	res, err := m.execIn(ctx, t.Container, cmd, env, sql)
	if err != nil {
		return false, err
	}
	if res.ExitCode != 0 {
		return false, &validate.InputError{Msg: "the database server refused it: " + redact(res.Output, t.RootPassword)}
	}
	for _, line := range strings.Split(res.Output, "\n") {
		if strings.TrimSpace(line) == "1" {
			return true, nil
		}
	}
	return false, nil
}

// dumpEngine says which engine made a dump, from the comment its tool writes at
// the top ("" when it is none of them).
func dumpEngine(head []byte) string {
	s := string(head)
	switch {
	case strings.Contains(s, "-- PostgreSQL database dump"):
		return "postgres"
	case strings.Contains(s, "-- MariaDB dump"):
		return "mariadb"
	case strings.Contains(s, "-- MySQL dump"):
		return "mysql"
	}
	return ""
}

// ReplaceDatabase loads a dump the agent made earlier over a database that
// already exists, together with its user: the database is dropped and created
// again, then the dump is loaded exactly as RestoreDatabase does. Everything is
// checked before anything is dropped (the dump is the agent's own and from the
// same engine, the database and the user exist); once it is dropped a failing
// load leaves it empty, which the error says. The caller takes a safety dump first.
func (m *Manager) ReplaceDatabase(ctx context.Context, t DatabaseTarget, db, user, server, file string) error {
	if err := t.validate(); err != nil {
		return err
	}
	if err := checkDatabaseName(db); err != nil {
		return err
	}
	if err := checkDatabaseUser(user); err != nil {
		return err
	}
	if err := validate.Name(server); err != nil {
		return err
	}
	if !dumpFileName.MatchString(file) {
		return &validate.InputError{Msg: "not a dump file name"}
	}
	if m.cfg.DryRun {
		return &validate.InputError{Msg: "restoring is not available in dry-run mode"}
	}
	if err := m.checkTarget(ctx, t); err != nil {
		return err
	}
	tmp, err := m.copyDump(server, file)
	if err != nil {
		return err
	}
	defer os.Remove(tmp)

	f, err := os.Open(tmp)
	if err != nil {
		return err
	}
	defer f.Close()
	zr, err := gzip.NewReader(f)
	if err != nil {
		return &validate.InputError{Msg: "the dump is not a valid gzip file"}
	}
	defer zr.Close()
	br := bufio.NewReaderSize(zr, 64<<10)
	head, _ := br.Peek(2048)
	if got := dumpEngine(head); got != t.Engine {
		if got == "" {
			return &validate.InputError{Msg: "this dump does not say which database engine made it, so it is not restored"}
		}
		return &validate.InputError{Msg: fmt.Sprintf("this dump was made by %s and this server runs %s, so it is not restored", got, t.Engine)}
	}

	if ok, err := m.exists(ctx, t, databaseExistsSQL(t.Engine, db)); err != nil {
		return err
	} else if !ok {
		return &validate.InputError{Msg: fmt.Sprintf("the database %q does not exist on this server, so there is nothing to replace", db)}
	}
	if ok, err := m.exists(ctx, t, userExistsSQL(t.Engine, user)); err != nil {
		return err
	} else if !ok {
		return &validate.InputError{Msg: fmt.Sprintf("the database user %q does not exist on this server", user)}
	}

	cmd, env := adminCommand(t)
	res, err := m.execIn(ctx, t.Container, cmd, env, replaceSQL(t.Engine, db, user))
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return &validate.InputError{Msg: "replacing the database failed, and it may now be missing or empty: " + redact(res.Output, t.RootPassword)}
	}

	cmd, env = restoreCommand(t, db, user)
	res, err = m.execFrom(ctx, t.Container, cmd, env, io.LimitReader(br, maxRestoreBytes), restoreTimeout)
	if err != nil {
		return &validate.InputError{Msg: "loading the dump did not finish, and the database is now empty: " + redact(err.Error(), t.RootPassword)}
	}
	if res.ExitCode != 0 {
		return &validate.InputError{Msg: "loading the dump failed, and the database is now empty: " + redact(res.Output, t.RootPassword)}
	}
	return nil
}
