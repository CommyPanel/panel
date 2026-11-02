package docker

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"

	"commypanel/agent/internal/validate"
)

// DatabaseTarget names a database server (a running container) and how to
// administer it from inside.
type DatabaseTarget struct {
	Container    string `json:"container"`
	Engine       string `json:"engine"`
	RootPassword string `json:"root_password"`
}

func (t DatabaseTarget) validate() error {
	if err := validate.Name(t.Container); err != nil {
		return err
	}
	if !strings.HasPrefix(t.Container, "db-") {
		return &validate.InputError{Msg: fmt.Sprintf("%q is not a database server", t.Container)}
	}
	if err := validate.DatabaseEngine(t.Engine); err != nil {
		return err
	}
	return validate.DatabasePassword(t.RootPassword)
}

// checkTarget makes sure the container really is a database server before its
// client tools are run in it: managed by CommyPanel and started from an
// official database image. Otherwise a name that merely starts with db- (an
// app called db-shop) would have the database client, and the administrator
// password, run inside the app's own container.
func (m *Manager) checkTarget(ctx context.Context, t DatabaseTarget) error {
	var inspect struct {
		Config struct {
			Image  string            `json:"Image"`
			Labels map[string]string `json:"Labels"`
		} `json:"Config"`
	}
	err := m.engine.doJSON(ctx, "GET", "/containers/"+t.Container+"/json", nil, nil, &inspect)
	if isStatus(err, 404) {
		return &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", t.Container)}
	}
	if err != nil {
		return err
	}
	if inspect.Config.Labels[ManagedLabel] != "true" || validate.DatabaseImage(inspect.Config.Image) != nil {
		return &validate.InputError{Msg: fmt.Sprintf("%q is not a database server", t.Container)}
	}
	return nil
}

// Names the engines keep for themselves; a database or user cannot be called
// any of these.
var reservedDatabases = map[string]bool{
	"postgres": true, "template0": true, "template1": true, "mysql": true,
	"information_schema": true, "performance_schema": true, "sys": true,
}
var reservedUsers = map[string]bool{
	"root": true, "postgres": true, "mysql": true, "mariadb": true, "sys": true, "admin": true,
	"replication": true, "healthcheck": true,
}

func checkDatabaseName(db string) error {
	if err := validate.DatabaseIdent(db); err != nil {
		return err
	}
	if reservedDatabases[db] {
		return &validate.InputError{Msg: fmt.Sprintf("%q is a name the database server keeps for itself", db)}
	}
	return nil
}

func checkDatabaseUser(user string) error {
	if err := validate.DatabaseIdent(user); err != nil {
		return err
	}
	if reservedUsers[user] || strings.HasPrefix(user, "pg_") || strings.HasPrefix(user, "mysql") {
		return &validate.InputError{Msg: fmt.Sprintf("%q is a name the database server keeps for itself", user)}
	}
	return nil
}

// adminCommand is how the engine's command line client is started inside its
// own container: as the administrator, reading SQL from stdin, with the
// password in the environment.
func adminCommand(t DatabaseTarget) (cmd, env []string) {
	switch t.Engine {
	case "postgres":
		// Local connections inside the official image are trusted, so no password is needed.
		return []string{"psql", "-U", "postgres", "-X", "-q", "-v", "ON_ERROR_STOP=1"}, nil
	case "mysql":
		return []string{"mysql", "-uroot", "--batch", "--skip-column-names"}, []string{"MYSQL_PWD=" + t.RootPassword}
	default:
		return []string{"mariadb", "-uroot", "--batch", "--skip-column-names"}, []string{"MYSQL_PWD=" + t.RootPassword}
	}
}

// Every value below went through validate.DatabaseIdent or DatabasePassword,
// which allow only letters, digits and underscores, so none of them can break
// out of the quotes around it. The SQL is fixed text around those values.

func createSQL(engine, db, user, password string) string {
	if engine == "postgres" {
		return fmt.Sprintf(`DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '%[2]s') THEN
    CREATE ROLE "%[2]s" LOGIN PASSWORD '%[3]s';
  ELSE
    ALTER ROLE "%[2]s" LOGIN PASSWORD '%[3]s';
  END IF;
END $$;
SELECT 'CREATE DATABASE "%[1]s" OWNER "%[2]s"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '%[1]s')\gexec
REVOKE ALL ON DATABASE "%[1]s" FROM PUBLIC;
GRANT ALL PRIVILEGES ON DATABASE "%[1]s" TO "%[2]s";
`, db, user, password)
	}
	return fmt.Sprintf("CREATE DATABASE IF NOT EXISTS `%[1]s` CHARACTER SET utf8mb4;\n"+
		"CREATE USER IF NOT EXISTS '%[2]s'@'%%' IDENTIFIED BY '%[3]s';\n"+
		"ALTER USER '%[2]s'@'%%' IDENTIFIED BY '%[3]s';\n"+
		"GRANT ALL PRIVILEGES ON `%[4]s`.* TO '%[2]s'@'%%';\n"+
		"FLUSH PRIVILEGES;\n", db, user, password, grantPattern(db))
}

// grantPattern is a database name as a GRANT target. There an underscore stands
// for any one character, so a user granted on shop_db would also reach shopxdb;
// escaping it makes the grant cover that one database only.
func grantPattern(db string) string {
	return strings.ReplaceAll(db, "_", `\_`)
}

func dropSQL(engine, db, user string) string {
	if engine == "postgres" {
		return fmt.Sprintf("DROP DATABASE IF EXISTS \"%s\" WITH (FORCE);\nDROP ROLE IF EXISTS \"%s\";\n", db, user)
	}
	return fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;\nDROP USER IF EXISTS '%s'@'%%';\nFLUSH PRIVILEGES;\n", db, user)
}

func passwordSQL(engine, user, password string) string {
	if engine == "postgres" {
		return fmt.Sprintf("ALTER ROLE \"%s\" PASSWORD '%s';\n", user, password)
	}
	return fmt.Sprintf("ALTER USER '%s'@'%%' IDENTIFIED BY '%s';\nFLUSH PRIVILEGES;\n", user, password)
}

// run executes SQL on a database server and turns a failure into an error that
// is safe to show: the secrets in play are removed from whatever the client said.
func (m *Manager) runSQL(ctx context.Context, t DatabaseTarget, sql string, secrets ...string) error {
	if err := m.checkTarget(ctx, t); err != nil {
		return err
	}
	cmd, env := adminCommand(t)
	res, err := m.execIn(ctx, t.Container, cmd, env, sql)
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return &validate.InputError{Msg: "the database server refused it: " + redact(res.Output, secrets...)}
	}
	return nil
}

func redact(text string, secrets ...string) string {
	for _, s := range secrets {
		if s != "" {
			text = strings.ReplaceAll(text, s, "***")
		}
	}
	text = strings.Join(strings.Fields(text), " ")
	if len(text) > 300 {
		text = text[:300] + "..."
	}
	if text == "" {
		return "no further details"
	}
	return text
}

// DatabaseReady reports whether the database server answers a query. A server
// that is still starting, or not running, is simply not ready yet.
func (m *Manager) DatabaseReady(ctx context.Context, t DatabaseTarget) (bool, error) {
	if err := t.validate(); err != nil {
		return false, err
	}
	if m.cfg.DryRun {
		return true, nil
	}
	if err := m.checkTarget(ctx, t); err != nil {
		var input *validate.InputError
		if asInput(err, &input) {
			return false, nil
		}
		return false, err
	}
	cmd, env := adminCommand(t)
	res, err := m.execIn(ctx, t.Container, cmd, env, "SELECT 1;\n")
	if err != nil {
		var input *validate.InputError
		if asInput(err, &input) {
			return false, nil
		}
		return false, err
	}
	return res.ExitCode == 0, nil
}

// CreateDatabase creates a database and a user that owns it and nothing else.
// Running it again with the same names sets the password and changes nothing else.
func (m *Manager) CreateDatabase(ctx context.Context, t DatabaseTarget, db, user, password string) error {
	if err := t.validate(); err != nil {
		return err
	}
	if err := checkDatabaseName(db); err != nil {
		return err
	}
	if err := checkDatabaseUser(user); err != nil {
		return err
	}
	if err := validate.DatabasePassword(password); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would create database", "container", t.Container, "database", db)
		return nil
	}
	return m.runSQL(ctx, t, createSQL(t.Engine, db, user, password), password, t.RootPassword)
}

// DropDatabase deletes a database and its user. What does not exist is not an error.
func (m *Manager) DropDatabase(ctx context.Context, t DatabaseTarget, db, user string) error {
	if err := t.validate(); err != nil {
		return err
	}
	if err := checkDatabaseName(db); err != nil {
		return err
	}
	if err := checkDatabaseUser(user); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would drop database", "container", t.Container, "database", db)
		return nil
	}
	return m.runSQL(ctx, t, dropSQL(t.Engine, db, user), t.RootPassword)
}

// SetDatabasePassword changes the password of a database user.
func (m *Manager) SetDatabasePassword(ctx context.Context, t DatabaseTarget, user, password string) error {
	if err := t.validate(); err != nil {
		return err
	}
	if err := checkDatabaseUser(user); err != nil {
		return err
	}
	if err := validate.DatabasePassword(password); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would change a database password", "container", t.Container, "user", user)
		return nil
	}
	return m.runSQL(ctx, t, passwordSQL(t.Engine, user, password), password, t.RootPassword)
}

// RemoveDatabaseData deletes the data folder of a database server. It refuses
// while any container still has the folder mounted, like removing a volume.
func (m *Manager) RemoveDatabaseData(ctx context.Context, server string) error {
	if err := validate.Name(server); err != nil {
		return err
	}
	resolved, err := validate.ResolveUnder(m.databasesDir(), filepath.Join(m.databasesDir(), server))
	if err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would remove database data", "path", resolved)
		return nil
	}
	if user, err := m.mountedBy(ctx, resolved); err != nil {
		return err
	} else if user != "" {
		return &validate.InputError{Msg: fmt.Sprintf("container %s still has this database's data mounted; remove it first", user)}
	}
	if err := os.RemoveAll(resolved); err != nil {
		return fmt.Errorf("remove database data: %w", err)
	}
	return nil
}

// dumpCommand is the engine's own backup tool, run inside the database's
// container. The output is plain SQL that recreates the database's content.
func dumpCommand(t DatabaseTarget, db string) (cmd, env []string) {
	switch t.Engine {
	case "postgres":
		return []string{"pg_dump", "-U", "postgres", "--no-owner", "--no-privileges", "-d", db}, nil
	case "mysql":
		return []string{"mysqldump", "-uroot", "--single-transaction", "--routines", "--triggers", "--no-tablespaces", db}, []string{"MYSQL_PWD=" + t.RootPassword}
	default:
		return []string{"mariadb-dump", "-uroot", "--single-transaction", "--routines", "--triggers", db}, []string{"MYSQL_PWD=" + t.RootPassword}
	}
}

// DumpDatabase writes a dump of one database to a temporary file under the data
// folder and returns its path. The caller reads it and then removes it. A failed
// dump leaves nothing behind and returns the tool's message, so a half-written
// backup is never mistaken for a good one.
func (m *Manager) DumpDatabase(ctx context.Context, t DatabaseTarget, db string) (string, error) {
	if err := t.validate(); err != nil {
		return "", err
	}
	if err := checkDatabaseName(db); err != nil {
		return "", err
	}
	if m.cfg.DryRun {
		return "", &validate.InputError{Msg: "dumps are not available in dry-run mode"}
	}

	tmpDir, err := m.stateDir("tmp")
	if err != nil {
		return "", err
	}
	f, err := os.CreateTemp(tmpDir, "dump-*.sql")
	if err != nil {
		return "", err
	}
	name := f.Name()
	fail := func(err error) (string, error) {
		f.Close()
		os.Remove(name)
		return "", err
	}

	if err := m.checkTarget(ctx, t); err != nil {
		return fail(err)
	}
	cmd, env := dumpCommand(t, db)
	res, err := m.execStream(ctx, t.Container, cmd, env, f)
	if err != nil {
		return fail(err)
	}
	if res.ExitCode != 0 {
		return fail(&validate.InputError{Msg: "the dump failed: " + redact(res.Output, t.RootPassword)})
	}
	if err := f.Close(); err != nil {
		os.Remove(name)
		return "", err
	}
	return name, nil
}
