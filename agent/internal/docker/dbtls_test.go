package docker

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

func withCert(t *testing.T, m *Manager, slug string) string {
	t.Helper()
	dir := dbtlsDir(m.cfg.StateDir, "db-"+slug)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "server.pem"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	return dir
}

func TestDatabaseTLSMountsCertificatesAndSetsTheCommand(t *testing.T) {
	flags := []string{"--ssl-ca=/commypanel-tls/ca.pem", "--ssl-cert=/commypanel-tls/server.pem", "--ssl-key=/commypanel-tls/server.key"}
	cases := map[string][]string{
		"mariadb:11.4": append([]string{"mariadbd"}, flags...),
		"mysql:8.4":    append([]string{"mysqld"}, flags...),
		"postgres:17": {"postgres", "-c", "ssl=on", "-c", "ssl_cert_file=/commypanel-tls/server.pem",
			"-c", "ssl_key_file=/commypanel-tls/server.key", "-c", "ssl_min_protocol_version=TLSv1.2"},
	}
	for image, want := range cases {
		m, d, _ := setup(t)
		dir := withCert(t, m, "main")
		spec := ContainerSpec{Name: "db-main", Image: image, DatabaseTLS: true,
			Volumes: []Volume{{Source: m.databasesDir() + "/main", Target: "/var/lib/data"}}}
		if err := m.Run(context.Background(), spec); err != nil {
			t.Fatalf("%s: %v", image, err)
		}
		cmd := toStrings(d.created["Cmd"])
		if !reflect.DeepEqual(cmd, want) {
			t.Errorf("%s: Cmd = %v, want %v", image, cmd, want)
		}
		binds := toStrings(d.created["HostConfig"].(map[string]any)["Binds"])
		wantBinds := []string{m.databasesDir() + "/main:/var/lib/data", dir + ":/commypanel-tls:ro"}
		if !reflect.DeepEqual(binds, wantBinds) {
			t.Errorf("%s: Binds = %v, want %v", image, binds, wantBinds)
		}
	}
}

func toStrings(v any) []string {
	var out []string
	for _, x := range v.([]any) {
		out = append(out, x.(string))
	}
	return out
}

func TestWithoutDatabaseTLSNothingChanges(t *testing.T) {
	m, d, _ := setup(t)
	withCert(t, m, "main")
	spec := ContainerSpec{Name: "db-main", Image: "mariadb:11.4", Volumes: []Volume{{Source: m.databasesDir() + "/main", Target: "/data"}}}
	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if _, has := d.created["Cmd"]; has {
		t.Error("a Cmd was set without database_tls")
	}
	binds := toStrings(d.created["HostConfig"].(map[string]any)["Binds"])
	if !reflect.DeepEqual(binds, []string{m.databasesDir() + "/main:/data"}) {
		t.Errorf("Binds = %v", binds)
	}
	raw, _ := json.Marshal(spec)
	if strings.Contains(string(raw), "database_tls") {
		t.Error("database_tls must be omitted when false")
	}
}

func TestDatabaseTLSIsOnlyForDatabaseServersWithACertificate(t *testing.T) {
	m, _, _ := setup(t)
	withCert(t, m, "main")
	bad := map[string]ContainerSpec{
		"app image":         {Name: "db-main", Image: "commypanel/shop:r1", DatabaseTLS: true},
		"app name":          {Name: "shop-1", Image: "mariadb:11.4", DatabaseTLS: true},
		"no certificate":    {Name: "db-other", Image: "mariadb:11.4", DatabaseTLS: true},
		"tool image":        {Name: "db-main", Image: "phpmyadmin:5", DatabaseTLS: true},
		"traversal in slug": {Name: "db-..", Image: "mariadb:11.4", DatabaseTLS: true},
		"db-prefix only":    {Name: "db-", Image: "mariadb:11.4", DatabaseTLS: true},
	}
	for name, spec := range bad {
		err := m.Validate(spec)
		var in *validate.InputError
		if err == nil || !asInput(err, &in) {
			t.Errorf("%s: err = %v, want an input error", name, err)
		}
	}
	if err := m.Validate(ContainerSpec{Name: "db-other", Image: "mariadb:11.4", DatabaseTLS: true}); err == nil || !strings.Contains(err.Error(), "no certificate for this server yet") {
		t.Errorf("missing certificate message = %v", err)
	}
	if err := m.Validate(ContainerSpec{Name: "db-main", Image: "mariadb:11.4", DatabaseTLS: true}); err != nil {
		t.Errorf("valid spec: %v", err)
	}
}

func TestTheCertificateFolderCannotBeMountedThroughVolumes(t *testing.T) {
	m, _, _ := setup(t)
	dir := withCert(t, m, "main")
	for _, src := range []string{dir, filepath.Dir(dir), m.cfg.StateDir} {
		for _, image := range []string{"mariadb:11.4", "commypanel/shop:r1"} {
			spec := ContainerSpec{Name: "db-main", Image: image, Volumes: []Volume{{Source: src, Target: "/x"}}}
			if err := m.Validate(spec); err == nil {
				t.Errorf("%s was accepted as a volume for %s", src, image)
			}
		}
	}
}

func remoteDaemon(t *testing.T, image string, running bool) *Manager {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/containers/db-main/json" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"no such container"}`))
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"Config": map[string]any{"Image": image, "Labels": map[string]string{ManagedLabel: "true"}},
			"State":  map[string]any{"Running": running},
			"NetworkSettings": map[string]any{"Networks": map[string]any{
				"commypanel-db-main": map[string]string{"IPAddress": "172.30.0.2"},
				"commypanel-link-d1": map[string]string{"IPAddress": "172.31.0.2"},
			}},
		})
	}))
	t.Cleanup(srv.Close)
	return NewManager(Config{DataDir: t.TempDir()}, NewEngine(srv.URL, nil))
}

func TestCheckDatabaseServerAndAddress(t *testing.T) {
	ctx := context.Background()
	m := remoteDaemon(t, "postgres:17", true)
	if err := m.CheckDatabaseServer(ctx, "main", "postgres"); err != nil {
		t.Errorf("matching engine: %v", err)
	}
	if err := m.CheckDatabaseServer(ctx, "main", "mysql"); err == nil {
		t.Error("an engine that does not match the image was accepted")
	}
	if err := m.CheckDatabaseServer(ctx, "missing", "postgres"); err == nil {
		t.Error("a missing container was accepted")
	}
	if err := m.CheckDatabaseServer(ctx, "../x", "postgres"); err == nil {
		t.Error("a bad slug was accepted")
	}
	ip, err := m.DatabaseAddress(ctx, "main")
	if err != nil || ip != "172.30.0.2" {
		t.Errorf("address = %q, %v (must be the server's own network)", ip, err)
	}
	if _, err := remoteDaemon(t, "postgres:17", false).DatabaseAddress(ctx, "main"); err == nil {
		t.Error("a stopped container has no address")
	}
}
