// Package dbtls makes the certificates a database server uses to speak TLS to
// remote clients: one small certificate authority per server and a server
// certificate signed by it. The panel hands the CA certificate to the person who
// connects, who then checks the server against it.
//
// Everything lives in the agent's state directory (root only, outside the data
// directory the panel user owns). The CA's private key never leaves its own
// folder; only the files the database needs (ca.pem, server.pem, server.key) are
// in the folder that is mounted, read-only, into the database container.
package dbtls

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"io/fs"
	"math/big"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"time"

	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/validate"
)

const (
	// Folder is the folder of the state directory that holds the certificates.
	Folder = "dbtls"
	// caKeyFolder holds the CA keys, away from the folders that are mounted into containers.
	caKeyFolder = ".ca"
	// DatabaseUID is the user the official database images run the server as.
	DatabaseUID = 999

	maxHosts    = 10
	caLifetime  = 10 * 365 * 24 * time.Hour
	certLife    = 365 * 24 * time.Hour
	renewWithin = 60 * 24 * time.Hour
)

// Config sets where the certificates live.
type Config struct {
	StateDir string
	// DryRun skips changing the owner of files (development, not root).
	DryRun bool
	// Chown overrides how a file in root is handed to the database user (tests).
	Chown func(root *os.Root, name string, uid, gid int) error
	// Now overrides the clock (tests).
	Now func() time.Time
}

// Result is what Ensure answers.
type Result struct {
	CAPEM   string `json:"ca_pem"`
	Changed bool   `json:"changed"`
}

// Manager makes and renews the certificates.
type Manager struct {
	cfg Config
	mu  sync.Mutex
}

func New(cfg Config) *Manager { return &Manager{cfg: cfg} }

func (m *Manager) now() time.Time {
	if m.cfg.Now != nil {
		return m.cfg.Now()
	}
	return time.Now()
}

// Dir is the folder mounted into the server's container.
func Dir(stateDir, server string) string { return filepath.Join(stateDir, Folder, server) }

// CheckHosts validates the names and addresses the certificate is made for and
// returns them in canonical form.
func CheckHosts(hosts []string) ([]string, error) {
	if len(hosts) == 0 || len(hosts) > maxHosts {
		return nil, &validate.InputError{Msg: "between 1 and 10 hosts are required"}
	}
	seen := map[string]bool{}
	out := make([]string, 0, len(hosts))
	for _, h := range hosts {
		if addr, err := netip.ParseAddr(h); err == nil {
			if addr.Zone() != "" || addr.IsUnspecified() || addr.IsLoopback() || addr.IsMulticast() || addr.Is4In6() {
				return nil, &validate.InputError{Msg: fmt.Sprintf("invalid host %q", h)}
			}
			h = addr.String()
		} else if err := validate.Domain(h); err != nil {
			return nil, &validate.InputError{Msg: fmt.Sprintf("invalid host %q", h)}
		}
		if !seen[h] {
			seen[h] = true
			out = append(out, h)
		}
	}
	return out, nil
}

// Ensure makes sure the server has a CA and a certificate valid for hosts. The
// certificate is made again (the CA kept) when the names differ or less than 60
// days are left; Changed says whether that happened.
func (m *Manager) Ensure(server, engine string, hosts []string) (Result, error) {
	if err := validate.DatabaseServer(server); err != nil {
		return Result{}, err
	}
	if err := validate.DatabaseEngine(engine); err != nil {
		return Result{}, err
	}
	hosts, err := CheckHosts(hosts)
	if err != nil {
		return Result{}, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	if err := statedir.Check(m.cfg.StateDir); err != nil {
		return Result{}, err
	}
	base := filepath.Join(m.cfg.StateDir, Folder)
	if err := privateDir(base, 0o700); err != nil {
		return Result{}, err
	}
	caKeyDir := filepath.Join(base, caKeyFolder)
	if err := privateDir(caKeyDir, 0o700); err != nil {
		return Result{}, err
	}
	dir := filepath.Join(base, server)
	if err := privateDir(dir, 0o755); err != nil {
		return Result{}, err
	}
	keys, err := os.OpenRoot(caKeyDir)
	if err != nil {
		return Result{}, err
	}
	defer keys.Close()
	files, err := os.OpenRoot(dir)
	if err != nil {
		return Result{}, err
	}
	defer files.Close()

	now := m.now()
	caName := server + ".key"
	ca, caKey, caPEM := m.loadCA(files, keys, caName, now)
	changed := false
	if ca == nil {
		if ca, caKey, caPEM, err = newCA(server, now); err != nil {
			return Result{}, err
		}
		keyDER, err := x509.MarshalECPrivateKey(caKey)
		if err != nil {
			return Result{}, err
		}
		if err := m.write(keys, caName, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600, false); err != nil {
			return Result{}, err
		}
		if err := m.write(files, "ca.pem", caPEM, 0o644, false); err != nil {
			return Result{}, err
		}
	}
	want := sans(server, hosts)
	if !m.leafOK(files, ca, want, now) {
		leafPEM, keyPEM, err := newLeaf(server, want, ca, caKey, now)
		if err != nil {
			return Result{}, err
		}
		if err := m.write(files, "server.key", keyPEM, 0o600, true); err != nil {
			return Result{}, err
		}
		if err := m.write(files, "server.pem", append(leafPEM, caPEM...), 0o644, true); err != nil {
			return Result{}, err
		}
		changed = true
	}
	return Result{CAPEM: string(caPEM), Changed: changed}, nil
}

// Remove deletes a server's certificates and CA key (best effort, idempotent).
func (m *Manager) Remove(server string) error {
	if err := validate.DatabaseServer(server); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	base := filepath.Join(m.cfg.StateDir, Folder)
	if err := os.RemoveAll(filepath.Join(base, server)); err != nil {
		return err
	}
	if err := os.Remove(filepath.Join(base, caKeyFolder, server+".key")); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	return nil
}

// privateDir creates dir if it is missing and refuses it when it is a link or
// belongs to somebody else.
func privateDir(dir string, mode os.FileMode) error {
	if err := os.Mkdir(dir, mode); err != nil && !errors.Is(err, fs.ErrExist) {
		return err
	}
	if err := statedir.Check(dir); err != nil {
		return err
	}
	// The umask may have narrowed it; the container needs to reach its own folder.
	if info, err := os.Lstat(dir); err == nil && info.Mode().Perm() != mode {
		return os.Chmod(dir, mode)
	}
	return nil
}

func sans(server string, hosts []string) []string {
	set := map[string]bool{"localhost": true, "db-" + server: true}
	for _, h := range hosts {
		set[h] = true
	}
	out := make([]string, 0, len(set))
	for h := range set {
		out = append(out, h)
	}
	sort.Strings(out)
	return out
}

func newSerial() (*big.Int, error) {
	return rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
}

func newCA(server string, now time.Time) (*x509.Certificate, *ecdsa.PrivateKey, []byte, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, nil, err
	}
	serial, err := newSerial()
	if err != nil {
		return nil, nil, nil, err
	}
	tpl := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: "CommyPanel database CA (" + server + ")"},
		NotBefore:             now.Add(-time.Hour),
		NotAfter:              now.Add(caLifetime),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		BasicConstraintsValid: true,
		IsCA:                  true,
		MaxPathLen:            0,
		MaxPathLenZero:        true,
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		return nil, nil, nil, err
	}
	cert, err := x509.ParseCertificate(der)
	if err != nil {
		return nil, nil, nil, err
	}
	return cert, key, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), nil
}

func newLeaf(server string, names []string, ca *x509.Certificate, caKey *ecdsa.PrivateKey, now time.Time) (certPEM, keyPEM []byte, err error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, err := newSerial()
	if err != nil {
		return nil, nil, err
	}
	tpl := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: "db-" + server},
		NotBefore:    now.Add(-time.Hour),
		NotAfter:     now.Add(certLife),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	for _, n := range names {
		if a, err := netip.ParseAddr(n); err == nil {
			tpl.IPAddresses = append(tpl.IPAddresses, a.AsSlice())
		} else {
			tpl.DNSNames = append(tpl.DNSNames, n)
		}
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, ca, &key.PublicKey, caKey)
	if err != nil {
		return nil, nil, err
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}),
		pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), nil
}

// loadCA reads the existing CA; any problem (missing, unreadable, expired,
// mismatched) returns nil so a new one is made.
func (m *Manager) loadCA(files, keys *os.Root, keyName string, now time.Time) (*x509.Certificate, *ecdsa.PrivateKey, []byte) {
	certPEM, err := readSmall(files, "ca.pem")
	if err != nil {
		return nil, nil, nil
	}
	keyPEM, err := readSmall(keys, keyName)
	if err != nil {
		return nil, nil, nil
	}
	cert, err := parseCert(certPEM)
	if err != nil || !cert.IsCA || now.After(cert.NotAfter.Add(-renewWithin)) {
		return nil, nil, nil
	}
	block, _ := pem.Decode(keyPEM)
	if block == nil {
		return nil, nil, nil
	}
	key, err := x509.ParseECPrivateKey(block.Bytes)
	if err != nil {
		return nil, nil, nil
	}
	pub, ok := cert.PublicKey.(*ecdsa.PublicKey)
	if !ok || !pub.Equal(&key.PublicKey) {
		return nil, nil, nil
	}
	return cert, key, certPEM
}

// leafOK says whether the server certificate on disk can stay: signed by this
// CA, for exactly these names, matching its key and with enough time left.
func (m *Manager) leafOK(files *os.Root, ca *x509.Certificate, want []string, now time.Time) bool {
	pemBytes, err := readSmall(files, "server.pem")
	if err != nil {
		return false
	}
	keyPEM, err := readSmall(files, "server.key")
	if err != nil {
		return false
	}
	leaf, err := parseCert(pemBytes)
	if err != nil || leaf.CheckSignatureFrom(ca) != nil || now.After(leaf.NotAfter.Add(-renewWithin)) || now.Before(leaf.NotBefore) {
		return false
	}
	block, _ := pem.Decode(keyPEM)
	if block == nil {
		return false
	}
	key, err := x509.ParseECPrivateKey(block.Bytes)
	if err != nil {
		return false
	}
	if pub, ok := leaf.PublicKey.(*ecdsa.PublicKey); !ok || !pub.Equal(&key.PublicKey) {
		return false
	}
	have := map[string]bool{}
	for _, d := range leaf.DNSNames {
		have[d] = true
	}
	for _, ip := range leaf.IPAddresses {
		if a, ok := netip.AddrFromSlice(ip); ok {
			have[a.Unmap().String()] = true
		}
	}
	if len(have) != len(want) {
		return false
	}
	for _, w := range want {
		if !have[w] {
			return false
		}
	}
	return true
}

func parseCert(b []byte) (*x509.Certificate, error) {
	block, _ := pem.Decode(b)
	if block == nil || block.Type != "CERTIFICATE" {
		return nil, errors.New("no certificate")
	}
	return x509.ParseCertificate(block.Bytes)
}

func readSmall(r *os.Root, name string) ([]byte, error) {
	f, err := r.Open(name)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() || info.Size() > 64<<10 {
		return nil, errors.New("unusable file")
	}
	var buf bytes.Buffer
	if _, err := buf.ReadFrom(f); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// write replaces name inside r atomically (temp file, then rename), with the
// given mode, handing it to the database user when asked.
func (m *Manager) write(r *os.Root, name string, data []byte, mode os.FileMode, toDatabase bool) error {
	tmp := name + ".tmp"
	_ = r.Remove(tmp)
	f, err := r.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	cleanup := func() { _ = r.Remove(tmp) }
	if _, err := f.Write(data); err != nil {
		f.Close()
		cleanup()
		return err
	}
	if err := f.Close(); err != nil {
		cleanup()
		return err
	}
	if err := r.Chmod(tmp, mode); err != nil {
		cleanup()
		return err
	}
	if toDatabase && !m.cfg.DryRun {
		chown := m.cfg.Chown
		if chown == nil {
			chown = func(r *os.Root, n string, uid, gid int) error { return r.Chown(n, uid, gid) }
		}
		if err := chown(r, tmp, DatabaseUID, DatabaseUID); err != nil {
			cleanup()
			return fmt.Errorf("hand certificate to the database user: %w", err)
		}
	}
	if err := r.Rename(tmp, name); err != nil {
		cleanup()
		return err
	}
	return nil
}
