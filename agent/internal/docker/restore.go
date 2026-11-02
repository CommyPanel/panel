package docker

import (
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"time"

	"commypanel/agent/internal/validate"
)

const (
	restoreTimeout = 30 * time.Minute
	// A dump this big, once unpacked, is refused: it is far beyond a gzip of a
	// real database and is how a decompression bomb would look.
	maxRestoreBytes = 200 << 30
)

// dumpFileName is how the panel names a dump: database, date, time.
var dumpFileName = regexp.MustCompile(`^[a-z][a-z0-9_]{0,30}-\d{8}-\d{6}\.sql\.gz$`)

// dumpRegistry is the folder where the agent remembers the checksum of every dump
// it made. It is in the agent's state directory, which the panel cannot write to, which is what
// makes a restore safe: only a file that is byte for byte something this agent
// dumped is ever loaded into a database server.
func (m *Manager) dumpRegistry() (string, error) {
	return m.stateDir("dumps")
}

// RegisterDump remembers the checksum (hex SHA-256) of a dump the agent made.
func (m *Manager) RegisterDump(sum string) error {
	if !sha256Hex.MatchString(sum) {
		return fmt.Errorf("not a checksum")
	}
	dir, err := m.dumpRegistry()
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(dir, sum), nil, 0o600)
}

var sha256Hex = regexp.MustCompile(`^[0-9a-f]{64}$`)

func (m *Manager) dumpRegistered(sum string) bool {
	if !sha256Hex.MatchString(sum) {
		return false
	}
	dir, err := m.dumpRegistry()
	if err != nil {
		return false
	}
	info, err := os.Lstat(filepath.Join(dir, sum))
	return err == nil && info.Mode().IsRegular()
}

// restoreCommand is the engine's client reading a dump from stdin into one
// database. PostgreSQL runs as the database's own user, so what it creates
// belongs to that user (the dump carries no owners); the MySQL family restores
// as the administrator, which its dump of routines and triggers needs.
func restoreCommand(t DatabaseTarget, db, user string) (cmd, env []string) {
	switch t.Engine {
	case "postgres":
		// Local connections inside the official image are trusted, so no password is needed.
		return []string{"psql", "-U", user, "-d", db, "-X", "-q", "-v", "ON_ERROR_STOP=1", "--single-transaction"}, nil
	case "mysql":
		return []string{"mysql", "-uroot", "--batch", db}, []string{"MYSQL_PWD=" + t.RootPassword}
	default:
		return []string{"mariadb", "-uroot", "--batch", db}, []string{"MYSQL_PWD=" + t.RootPassword}
	}
}

// RestoreDatabase loads a dump the agent made earlier into an existing, empty
// database. server and file say where the panel keeps it, below
// <data>/backups/databases; the file is copied out, checked against the
// registry and only then fed to the database client, so it cannot be swapped
// for something else in between.
func (m *Manager) RestoreDatabase(ctx context.Context, t DatabaseTarget, db, user, server, file string) error {
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

	cmd, env := restoreCommand(t, db, user)
	res, err := m.execFrom(ctx, t.Container, cmd, env, io.LimitReader(zr, maxRestoreBytes), restoreTimeout)
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return &validate.InputError{Msg: "loading the dump failed: " + redact(res.Output, t.RootPassword)}
	}
	return nil
}

// copyDump copies a dump out of the panel's backup folder into a private file
// while hashing it, refuses it unless this agent made it, and returns the
// private copy's path.
func (m *Manager) copyDump(server, file string) (string, error) {
	root, err := os.OpenRoot(filepath.Join(m.cfg.DataDir, "backups", "databases"))
	if err != nil {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	defer root.Close()

	rel := filepath.Join(server, file)
	if info, err := root.Lstat(rel); err != nil || !info.Mode().IsRegular() {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	src, err := root.Open(rel)
	if err != nil {
		return "", &validate.InputError{Msg: "there is no such backup"}
	}
	defer src.Close()

	tmpDir, err := m.scratch()
	if err != nil {
		return "", err
	}
	dst, err := os.CreateTemp(tmpDir, "restore-*.sql.gz")
	if err != nil {
		return "", err
	}
	fail := func(err error) (string, error) {
		dst.Close()
		os.Remove(dst.Name())
		return "", err
	}

	h := sha256.New()
	if _, err := io.Copy(io.MultiWriter(dst, h), src); err != nil {
		return fail(err)
	}
	if err := dst.Close(); err != nil {
		os.Remove(dst.Name())
		return "", err
	}
	if !m.dumpRegistered(hex.EncodeToString(h.Sum(nil))) {
		os.Remove(dst.Name())
		return "", &validate.InputError{Msg: "this file was not made by CommyPanel, or was changed since, so it is not restored"}
	}
	return dst.Name(), nil
}
