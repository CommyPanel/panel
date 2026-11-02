package certs

import (
	"context"
	"crypto/ecdsa"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"commypanel/agent/internal/validate"
)

func setup(t *testing.T) (*Manager, *fakeCA, string) {
	t.Helper()
	dir := t.TempDir()
	ca := newFakeCA(t, filepath.Join(dir, "acme-webroot"))
	m := NewManager(Config{DataDir: dir, StateDir: dir, ProductionURL: ca.directoryURL(), StagingURL: "http://127.0.0.1:1/never", Timeout: 30 * time.Second})
	return m, ca, dir
}

func request(domains ...string) Request {
	return Request{Name: "shop", Domains: domains, Email: "ops@example.com", AgreeTOS: true}
}

func TestIssueStoresChainAndKey(t *testing.T) {
	m, _, dir := setup(t)

	info, err := m.Issue(context.Background(), request("shop.example.com", "www.shop.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(info.Domains, ",") != "shop.example.com,www.shop.example.com" {
		t.Errorf("domains = %v", info.Domains)
	}
	if time.Until(info.NotAfter) < 80*24*time.Hour {
		t.Errorf("expiry too soon: %v", info.NotAfter)
	}

	certDir := filepath.Join(dir, "certs", "shop")
	for _, f := range []string{"fullchain.pem", "privkey.pem"} {
		st, err := os.Stat(filepath.Join(certDir, f))
		if err != nil {
			t.Fatal(err)
		}
		if st.Mode().Perm() != 0o600 {
			t.Errorf("%s mode = %v, want 0600", f, st.Mode().Perm())
		}
	}
	if st, _ := os.Stat(certDir); st.Mode().Perm() != 0o700 {
		t.Errorf("cert dir mode = %v, want 0700", st.Mode().Perm())
	}

	// fullchain holds leaf + CA, and the key matches the leaf.
	data, _ := os.ReadFile(filepath.Join(certDir, "fullchain.pem"))
	var certs []*x509.Certificate
	for rest := data; ; {
		var block *pem.Block
		block, rest = pem.Decode(rest)
		if block == nil {
			break
		}
		c, err := x509.ParseCertificate(block.Bytes)
		if err != nil {
			t.Fatal(err)
		}
		certs = append(certs, c)
	}
	if len(certs) != 2 {
		t.Fatalf("chain has %d certificates, want 2", len(certs))
	}
	keyPEM, _ := os.ReadFile(filepath.Join(certDir, "privkey.pem"))
	block, _ := pem.Decode(keyPEM)
	key, err := x509.ParseECPrivateKey(block.Bytes)
	if err != nil {
		t.Fatal(err)
	}
	if !key.PublicKey.Equal(certs[0].PublicKey.(*ecdsa.PublicKey)) {
		t.Error("private key does not belong to the certificate")
	}
}

func TestChallengeFilesAreCleanedUp(t *testing.T) {
	m, _, dir := setup(t)
	if _, err := m.Issue(context.Background(), request("shop.example.com")); err != nil {
		t.Fatal(err)
	}
	left, _ := filepath.Glob(filepath.Join(dir, "acme-webroot", ".well-known", "acme-challenge", "*"))
	if len(left) != 0 {
		t.Errorf("challenge files left behind: %v", left)
	}
}

func TestAccountKeyIsReused(t *testing.T) {
	m, ca, dir := setup(t)
	for i := 0; i < 2; i++ {
		if _, err := m.Issue(context.Background(), request("shop.example.com")); err != nil {
			t.Fatalf("issue %d: %v", i, err)
		}
	}
	keys, _ := filepath.Glob(filepath.Join(dir, "acme", "*-account.pem"))
	if len(keys) != 1 {
		t.Fatalf("account keys = %v", keys)
	}
	if st, _ := os.Stat(keys[0]); st.Mode().Perm() != 0o600 {
		t.Errorf("account key mode = %v", st.Mode().Perm())
	}
	if ca.accountsCreated() != 2 {
		t.Errorf("registration calls = %d (second must find the existing account)", ca.accountsCreated())
	}
}

func TestStagingUsesTheStagingDirectory(t *testing.T) {
	dir := t.TempDir()
	prod := newFakeCA(t, filepath.Join(dir, "acme-webroot"))
	staging := newFakeCA(t, filepath.Join(dir, "acme-webroot"))
	m := NewManager(Config{DataDir: dir, StateDir: dir, ProductionURL: prod.directoryURL(), StagingURL: staging.directoryURL()})

	req := request("shop.example.com")
	req.Staging = true
	if _, err := m.Issue(context.Background(), req); err != nil {
		t.Fatal(err)
	}
	if staging.accountsCreated() != 1 || prod.accountsCreated() != 0 {
		t.Errorf("staging=%d production=%d", staging.accountsCreated(), prod.accountsCreated())
	}
	if _, err := os.Stat(filepath.Join(dir, "acme", "staging-account.pem")); err != nil {
		t.Errorf("staging account key missing: %v", err)
	}
}

func TestFailedValidationReportsTheCAsReasonAndStoresNothing(t *testing.T) {
	m, ca, dir := setup(t)
	// A CA that cannot find the token: remove the webroot write by making the
	// token path unreadable through a name the agent will not write to.
	ca.webroot = filepath.Join(dir, "somewhere-else")

	_, err := m.Issue(context.Background(), request("shop.example.com"))
	if err == nil {
		t.Fatal("want error")
	}
	if !strings.Contains(err.Error(), "shop.example.com") || !strings.Contains(err.Error(), "Timeout during connect") {
		t.Errorf("error should explain which domain failed and why: %v", err)
	}
	if _, statErr := os.Stat(filepath.Join(dir, "certs", "shop")); !os.IsNotExist(statErr) {
		t.Error("nothing may be stored after a failed validation")
	}
}

func TestOverwritesAnExistingCertificate(t *testing.T) {
	m, _, _ := setup(t)
	first, err := m.Issue(context.Background(), request("shop.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	second, err := m.Issue(context.Background(), request("shop.example.com", "www.shop.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if first.Serial == second.Serial || len(second.Domains) != 2 {
		t.Errorf("first=%v second=%v", first, second)
	}
	stored, _ := m.Info("shop")
	if stored.Serial != second.Serial {
		t.Error("the stored certificate must be the new one")
	}
}

func TestAHostileTokenCannotChooseAPath(t *testing.T) {
	m, ca, dir := setup(t)
	ca.forceToken = "../../../../escape-attempt-xxxxxxxx"

	if _, err := m.Issue(context.Background(), request("shop.example.com")); err == nil || !strings.Contains(err.Error(), "unexpected challenge token") {
		t.Fatalf("want a token error, got %v", err)
	}
	found, _ := filepath.Glob(filepath.Join(dir, "*escape*"))
	if len(found) != 0 {
		t.Errorf("a file was written outside the webroot: %v", found)
	}
}

func TestValidation(t *testing.T) {
	m, _, dir := setup(t)

	many := make([]string, 101)
	for i := range many {
		many[i] = "a" + strings.Repeat("b", i%5) + ".example.com"
	}
	cases := map[string]Request{
		"no agreement":    {Name: "shop", Domains: []string{"a.example.com"}},
		"bad name":        {Name: "../x", Domains: []string{"a.example.com"}, AgreeTOS: true},
		"no domains":      {Name: "shop", AgreeTOS: true},
		"bad domain":      {Name: "shop", Domains: []string{"a.example.com;"}, AgreeTOS: true},
		"wildcard":        {Name: "shop", Domains: []string{"*.example.com"}, AgreeTOS: true},
		"duplicate":       {Name: "shop", Domains: []string{"a.example.com", "a.example.com"}, AgreeTOS: true},
		"too many":        {Name: "shop", Domains: many, AgreeTOS: true},
		"bad email":       {Name: "shop", Domains: []string{"a.example.com"}, Email: "not an email", AgreeTOS: true},
		"email injection": {Name: "shop", Domains: []string{"a.example.com"}, Email: "a@b.com\nbcc:x@y.z", AgreeTOS: true},
	}
	for name, req := range cases {
		_, err := m.Issue(context.Background(), req)
		var ie *validate.InputError
		if !errors.As(err, &ie) {
			t.Errorf("%s: want *InputError, got %v", name, err)
		}
	}
	if entries, _ := os.ReadDir(dir); len(entries) > 1 { // only the fake CA's webroot dir may exist
		t.Errorf("invalid requests must not write anything: %v", entries)
	}
}

func TestInfoAndRemove(t *testing.T) {
	m, _, _ := setup(t)

	if info, err := m.Info("shop"); err != nil || info != nil {
		t.Fatalf("missing certificate: info=%v err=%v", info, err)
	}
	if _, err := m.Issue(context.Background(), request("shop.example.com")); err != nil {
		t.Fatal(err)
	}
	info, err := m.Info("shop")
	if err != nil || info == nil || info.Domains[0] != "shop.example.com" {
		t.Fatalf("info = %v, err = %v", info, err)
	}
	if err := m.Remove("shop"); err != nil {
		t.Fatal(err)
	}
	if info, _ := m.Info("shop"); info != nil {
		t.Error("certificate should be gone")
	}
	if err := m.Remove("shop"); err != nil {
		t.Errorf("removing a missing certificate should succeed: %v", err)
	}
	if err := m.Remove("../x"); err == nil {
		t.Error("invalid name must be rejected")
	}
	if _, err := m.Info("../x"); err == nil {
		t.Error("invalid name must be rejected")
	}
}

type noNetwork struct{ t *testing.T }

func (n noNetwork) RoundTrip(*http.Request) (*http.Response, error) {
	n.t.Error("dry run must never contact a CA")
	return nil, errors.New("network disabled")
}

func TestDryRunIssuesASelfSignedCertificateOffline(t *testing.T) {
	dir := t.TempDir()
	m := NewManager(Config{DataDir: dir, StateDir: dir, DryRun: true, HTTPClient: &http.Client{Transport: noNetwork{t}}})

	info, err := m.Issue(context.Background(), request("shop.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Issuer != "CommyPanel dry run" || info.Domains[0] != "shop.example.com" {
		t.Errorf("info = %+v", info)
	}
	if _, err := os.Stat(filepath.Join(dir, "certs", "shop", "privkey.pem")); err != nil {
		t.Error(err)
	}
	if entries, _ := os.ReadDir(filepath.Join(dir, "acme")); len(entries) != 0 {
		t.Errorf("dry run must not create an ACME account: %v", entries)
	}
}
