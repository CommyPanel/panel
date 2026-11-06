package dbtls

import (
	"crypto/x509"
	"encoding/pem"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type chowned struct {
	name     string
	uid, gid int
}

func newManager(t *testing.T) (*Manager, string, *[]chowned, *time.Time) {
	t.Helper()
	state := t.TempDir()
	var calls []chowned
	now := time.Now()
	m := New(Config{StateDir: state,
		Chown: func(_ *os.Root, name string, uid, gid int) error {
			calls = append(calls, chowned{name, uid, gid})
			return nil
		},
		Now: func() time.Time { return now },
	})
	return m, state, &calls, &now
}

func certs(t *testing.T, b []byte) []*x509.Certificate {
	t.Helper()
	var out []*x509.Certificate
	for {
		var block *pem.Block
		block, b = pem.Decode(b)
		if block == nil {
			return out
		}
		c, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, c)
	}
}

func TestEnsureMakesACAAndAVerifiableServerCertificate(t *testing.T) {
	m, state, calls, _ := newManager(t)
	hosts := []string{"203.0.113.5", "2001:db8::1", "panel.example.com"}
	res, err := m.Ensure("main", "postgres", hosts)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Changed || !strings.HasPrefix(res.CAPEM, "-----BEGIN CERTIFICATE-----") {
		t.Fatalf("result = %+v", res)
	}
	dir := filepath.Join(state, "dbtls", "main")
	chain := certs(t, mustRead(t, filepath.Join(dir, "server.pem")))
	if len(chain) != 2 {
		t.Fatalf("server.pem holds %d certificates, want leaf then CA", len(chain))
	}
	leaf, ca := chain[0], chain[1]
	if got := certs(t, []byte(res.CAPEM)); len(got) != 1 || !got[0].Equal(ca) {
		t.Error("ca_pem is not the CA in server.pem")
	}
	if leaf.Subject.CommonName != "db-main" || !ca.IsCA || ca.NotAfter.Before(time.Now().Add(9*365*24*time.Hour)) {
		t.Errorf("leaf CN %q, CA %v until %v", leaf.Subject.CommonName, ca.IsCA, ca.NotAfter)
	}
	if d := leaf.NotAfter.Sub(time.Now()); d < 360*24*time.Hour || d > 366*24*time.Hour {
		t.Errorf("leaf lifetime %v", d)
	}
	pool := x509.NewCertPool()
	pool.AddCert(ca)
	for _, name := range []string{"203.0.113.5", "2001:db8::1", "panel.example.com", "localhost", "db-main"} {
		if err := leaf.VerifyHostname(name); err != nil {
			t.Errorf("hostname %s: %v", name, err)
		}
	}
	if err := leaf.VerifyHostname("other.example.com"); err == nil {
		t.Error("certificate is valid for a host it was not made for")
	}
	if _, err := leaf.Verify(x509.VerifyOptions{Roots: pool, DNSName: "panel.example.com", KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}); err != nil {
		t.Errorf("CA does not verify the leaf: %v", err)
	}
	if len(leaf.IPAddresses) != 2 || len(leaf.DNSNames) != 3 {
		t.Errorf("SANs: %v %v", leaf.IPAddresses, leaf.DNSNames)
	}

	// Modes, and what is handed to the database user.
	for name, want := range map[string]os.FileMode{"server.key": 0o600, "server.pem": 0o644, "ca.pem": 0o644} {
		if got := mode(t, filepath.Join(dir, name)); got != want {
			t.Errorf("%s mode = %o, want %o", name, got, want)
		}
	}
	if got := mode(t, dir); got != 0o755 {
		t.Errorf("folder mode = %o", got)
	}
	caKey := filepath.Join(state, "dbtls", ".ca", "main.key")
	if got := mode(t, caKey); got != 0o600 {
		t.Errorf("ca key mode = %o", got)
	}
	if _, err := os.Stat(filepath.Join(dir, "ca.key")); err == nil {
		t.Error("the CA key is inside the folder that is mounted into the container")
	}
	want := map[string]bool{"server.key.tmp": true, "server.pem.tmp": true}
	for _, c := range *calls {
		if !want[c.name] || c.uid != 999 || c.gid != 999 {
			t.Errorf("unexpected chown %+v", c)
		}
		delete(want, c.name)
	}
	if len(want) != 0 {
		t.Errorf("not handed to the database user: %v", want)
	}
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), ".tmp") {
			t.Errorf("leftover temp file %s", e.Name())
		}
	}
}

func TestEnsureIsStableAndRegeneratesOnlyWhenNeeded(t *testing.T) {
	m, state, _, now := newManager(t)
	hosts := []string{"203.0.113.5"}
	first, err := m.Ensure("main", "mysql", hosts)
	if err != nil {
		t.Fatal(err)
	}
	pemPath := filepath.Join(state, "dbtls", "main", "server.pem")
	before := mustRead(t, pemPath)

	// Same hosts, in another order and with a duplicate: nothing changes.
	again, err := m.Ensure("main", "mysql", []string{"203.0.113.5", "203.0.113.5"})
	if err != nil || again.Changed || again.CAPEM != first.CAPEM || string(mustRead(t, pemPath)) != string(before) {
		t.Fatalf("second call: %+v %v", again, err)
	}

	// A different set of hosts: new certificate, same CA.
	third, err := m.Ensure("main", "mysql", []string{"203.0.113.5", "db.example.com"})
	if err != nil || !third.Changed || third.CAPEM != first.CAPEM {
		t.Fatalf("new hosts: %+v %v", third, err)
	}
	if string(mustRead(t, pemPath)) == string(before) {
		t.Error("server.pem was not rewritten")
	}

	// Less than 60 days left: renewed, CA kept.
	*now = now.Add(310 * 24 * time.Hour)
	renewed, err := m.Ensure("main", "mysql", []string{"203.0.113.5", "db.example.com"})
	if err != nil || !renewed.Changed || renewed.CAPEM != first.CAPEM {
		t.Fatalf("renewal: %+v %v", renewed, err)
	}
	if again, _ := m.Ensure("main", "mysql", []string{"203.0.113.5", "db.example.com"}); again.Changed {
		t.Error("changed right after a renewal")
	}
}

func TestEnsureReplacesAMismatchedOrDamagedCertificate(t *testing.T) {
	m, state, _, _ := newManager(t)
	hosts := []string{"203.0.113.5"}
	first, _ := m.Ensure("main", "mysql", hosts)
	dir := filepath.Join(state, "dbtls", "main")
	if err := os.WriteFile(filepath.Join(dir, "server.key"), []byte("garbage"), 0o600); err != nil {
		t.Fatal(err)
	}
	res, err := m.Ensure("main", "mysql", hosts)
	if err != nil || !res.Changed || res.CAPEM != first.CAPEM {
		t.Fatalf("damaged key: %+v %v", res, err)
	}
	// Losing the CA key makes a new CA, because the old one could not sign anything.
	if err := os.Remove(filepath.Join(state, "dbtls", ".ca", "main.key")); err != nil {
		t.Fatal(err)
	}
	res, err = m.Ensure("main", "mysql", hosts)
	if err != nil || !res.Changed || res.CAPEM == first.CAPEM {
		t.Fatalf("lost CA key: changed=%v same CA=%v err=%v", res.Changed, res.CAPEM == first.CAPEM, err)
	}
}

func TestEnsureRefusesBadInput(t *testing.T) {
	m, _, _, _ := newManager(t)
	tooMany := make([]string, 11)
	for i := range tooMany {
		tooMany[i] = "10.0.0." + string(rune('1'+i))
	}
	cases := map[string]struct {
		server, engine string
		hosts          []string
	}{
		"traversal":     {"../x", "mysql", []string{"203.0.113.5"}},
		"uppercase":     {"Main", "mysql", []string{"203.0.113.5"}},
		"engine":        {"main", "sqlite", []string{"203.0.113.5"}},
		"no hosts":      {"main", "mysql", nil},
		"wildcard":      {"main", "mysql", []string{"*.example.com"}},
		"one label":     {"main", "mysql", []string{"intranet"}},
		"loopback":      {"main", "mysql", []string{"127.0.0.1"}},
		"unspecified":   {"main", "mysql", []string{"0.0.0.0"}},
		"zone":          {"main", "mysql", []string{"fe80::1%eth0"}},
		"too many":      {"main", "mysql", tooMany},
		"inject":        {"main", "mysql", []string{"a.example.com\nb"}},
		"uppercase dns": {"main", "mysql", []string{"A.example.com"}},
	}
	for name, c := range cases {
		if _, err := m.Ensure(c.server, c.engine, c.hosts); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestEnsureRefusesAFolderThatIsALink(t *testing.T) {
	m, state, _, _ := newManager(t)
	if err := os.MkdirAll(filepath.Join(state, "dbtls"), 0o700); err != nil {
		t.Fatal(err)
	}
	elsewhere := t.TempDir()
	if err := os.Symlink(elsewhere, filepath.Join(state, "dbtls", "main")); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Ensure("main", "mysql", []string{"203.0.113.5"}); err == nil {
		t.Fatal("a linked folder was used")
	}
	if entries, _ := os.ReadDir(elsewhere); len(entries) != 0 {
		t.Error("files were written through the link")
	}
}

func TestRemoveDeletesEverything(t *testing.T) {
	m, state, _, _ := newManager(t)
	if _, err := m.Ensure("main", "mysql", []string{"203.0.113.5"}); err != nil {
		t.Fatal(err)
	}
	if err := m.Remove("main"); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{filepath.Join(state, "dbtls", "main"), filepath.Join(state, "dbtls", ".ca", "main.key")} {
		if _, err := os.Stat(p); err == nil {
			t.Errorf("%s still exists", p)
		}
	}
	if err := m.Remove("main"); err != nil {
		t.Errorf("removing twice: %v", err)
	}
}

func mustRead(t *testing.T, p string) []byte {
	t.Helper()
	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func mode(t *testing.T, p string) os.FileMode {
	t.Helper()
	info, err := os.Stat(p)
	if err != nil {
		t.Fatal(err)
	}
	return info.Mode().Perm()
}
