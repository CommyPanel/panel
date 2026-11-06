package docker

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"commypanel/agent/internal/validate"
)

// dbtlsMount is where a database container sees its certificate folder.
const dbtlsMount = "/commypanel-tls"

// dbtlsDir is the agent's certificate folder of the database server named by its
// container (db-<slug>), inside the state directory.
func dbtlsDir(stateDir, container string) string {
	return filepath.Join(stateDir, "dbtls", strings.TrimPrefix(container, "db-"))
}

// engineOf names the engine of an official database image.
func engineOf(image string) string {
	name, _, _ := strings.Cut(image, ":")
	return name
}

// checkDatabaseTLS checks what database_tls may be used for: a database server
// (db-<slug> from an official database image) whose certificate exists.
func (m *Manager) checkDatabaseTLS(s ContainerSpec) error {
	if validate.DatabaseImage(s.Image) != nil || !strings.HasPrefix(s.Name, "db-") {
		return &validate.InputError{Msg: "database_tls is only for database servers"}
	}
	if err := validate.DatabaseServer(strings.TrimPrefix(s.Name, "db-")); err != nil {
		return err
	}
	info, err := os.Stat(filepath.Join(dbtlsDir(m.cfg.StateDir, s.Name), "server.pem"))
	if err != nil || !info.Mode().IsRegular() {
		return &validate.InputError{Msg: "no certificate for this server yet"}
	}
	return nil
}

// databaseTLSCommand is the engine's start command with TLS switched on, using
// the files in dbtlsMount.
func databaseTLSCommand(image string) []string {
	switch engineOf(image) {
	case "postgres":
		return []string{"postgres", "-c", "ssl=on",
			"-c", "ssl_cert_file=" + dbtlsMount + "/server.pem",
			"-c", "ssl_key_file=" + dbtlsMount + "/server.key",
			"-c", "ssl_min_protocol_version=TLSv1.2"}
	case "mysql":
		return append([]string{"mysqld"}, mysqlTLSFlags()...)
	default:
		return append([]string{"mariadbd"}, mysqlTLSFlags()...)
	}
}

func mysqlTLSFlags() []string {
	return []string{"--ssl-ca=" + dbtlsMount + "/ca.pem", "--ssl-cert=" + dbtlsMount + "/server.pem", "--ssl-key=" + dbtlsMount + "/server.key"}
}

// CheckDatabaseServer makes sure db-<server> is a running-or-not managed
// container of an official image of this engine (the check behind every action
// on a database server, plus the engine matching the image).
func (m *Manager) CheckDatabaseServer(ctx context.Context, server, engine string) error {
	if err := validate.DatabaseServer(server); err != nil {
		return err
	}
	if err := validate.DatabaseEngine(engine); err != nil {
		return err
	}
	if m.cfg.DryRun {
		return nil
	}
	image, err := m.inspectDatabase(ctx, "db-"+server)
	if err != nil {
		return err
	}
	if engineOf(image) != engine {
		return &validate.InputError{Msg: fmt.Sprintf("db-%s does not run %s", server, engine)}
	}
	return nil
}

// DatabaseAddress is the IP address of db-<server> on its own network, looked up
// fresh each time because it can change when the container restarts.
func (m *Manager) DatabaseAddress(ctx context.Context, server string) (string, error) {
	if err := validate.DatabaseServer(server); err != nil {
		return "", err
	}
	if m.cfg.DryRun {
		return "", fmt.Errorf("dry-run: no database server to reach")
	}
	var inspect struct {
		State struct {
			Running bool `json:"Running"`
		} `json:"State"`
		NetworkSettings struct {
			Networks map[string]struct {
				IPAddress string `json:"IPAddress"`
			} `json:"Networks"`
		} `json:"NetworkSettings"`
	}
	if err := m.engine.doJSON(ctx, "GET", "/containers/db-"+server+"/json", nil, nil, &inspect); err != nil {
		return "", err
	}
	ip := inspect.NetworkSettings.Networks["commypanel-db-"+server].IPAddress
	if !inspect.State.Running || ip == "" {
		return "", fmt.Errorf("db-%s is not running", server)
	}
	return ip, nil
}
