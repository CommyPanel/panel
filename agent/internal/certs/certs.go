// Package certs obtains and stores TLS certificates from Let's Encrypt using
// the ACME HTTP-01 challenge, which nginx serves from the ACME webroot.
package certs

import (
	"bytes"
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/acme"

	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/validate"
)

const (
	// ProductionURL and StagingURL are the only ACME directories the agent
	// will talk to; callers choose between them, never supply a URL.
	ProductionURL = "https://acme-v02.api.letsencrypt.org/directory"
	StagingURL    = "https://acme-staging-v02.api.letsencrypt.org/directory"

	maxDomains = 100
)

var (
	tokenRe = regexp.MustCompile(`^[A-Za-z0-9_-]{8,200}$`)
	emailRe = regexp.MustCompile(`^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$`)
)

// Config sets where files live and which CA to use. The URL and client
// fields exist so tests can point the manager at a fake CA.
type Config struct {
	DataDir       string
	StateDir      string // the agent's private folder: certificates, account keys, challenge files
	DryRun        bool   // issue self-signed certificates and never contact a CA
	ProductionURL string
	StagingURL    string
	HTTPClient    *http.Client
	Timeout       time.Duration
	UserAgent     string
}

func (c Config) withDefaults() Config {
	if c.StateDir == "" {
		c.StateDir = statedir.Default(c.DataDir) // development and tests; a real install passes --state-dir
	}
	if c.ProductionURL == "" {
		c.ProductionURL = ProductionURL
	}
	if c.StagingURL == "" {
		c.StagingURL = StagingURL
	}
	if c.Timeout == 0 {
		c.Timeout = 5 * time.Minute
	}
	if c.UserAgent == "" {
		c.UserAgent = "commypanel-agent"
	}
	return c
}

// Request asks for a certificate covering the given domains.
type Request struct {
	Name    string   `json:"name"`
	Domains []string `json:"domains"`
	Email   string   `json:"email,omitempty"`
	Staging bool     `json:"staging,omitempty"`
	// AgreeTOS records that the user accepted the CA's subscriber agreement.
	AgreeTOS bool `json:"agree_tos"`
}

// Info describes a stored certificate.
type Info struct {
	Name      string    `json:"name"`
	Domains   []string  `json:"domains"`
	NotBefore time.Time `json:"not_before"`
	NotAfter  time.Time `json:"not_after"`
	Issuer    string    `json:"issuer"`
	Serial    string    `json:"serial"`
}

// Manager issues and stores certificates. Issuing is serialised so the agent
// never opens parallel orders against the CA.
type Manager struct {
	cfg Config
	mu  sync.Mutex
}

func NewManager(cfg Config) *Manager { return &Manager{cfg: cfg.withDefaults()} }

func (m *Manager) certDir(name string) string { return filepath.Join(m.cfg.StateDir, "certs", name) }
func (m *Manager) webroot() string            { return filepath.Join(m.cfg.StateDir, "acme-webroot") }

// Validate checks a request without doing anything.
func (m *Manager) Validate(r Request) error {
	if err := validate.Name(r.Name); err != nil {
		return err
	}
	if !r.AgreeTOS {
		return &validate.InputError{Msg: "the Let's Encrypt subscriber agreement must be accepted (agree_tos)"}
	}
	if len(r.Domains) == 0 || len(r.Domains) > maxDomains {
		return &validate.InputError{Msg: fmt.Sprintf("a certificate needs between 1 and %d domains", maxDomains)}
	}
	seen := map[string]bool{}
	for _, d := range r.Domains {
		if err := validate.Domain(d); err != nil {
			return err
		}
		if seen[d] {
			return &validate.InputError{Msg: fmt.Sprintf("duplicate domain %q", d)}
		}
		seen[d] = true
	}
	if r.Email != "" && (len(r.Email) > 254 || !emailRe.MatchString(r.Email)) {
		return &validate.InputError{Msg: "invalid email address"}
	}
	return nil
}

// Issue obtains a certificate and stores it under <state>/certs/<name>.
func (m *Manager) Issue(ctx context.Context, r Request) (Info, error) {
	if err := m.Validate(r); err != nil {
		return Info{}, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, m.cfg.Timeout)
	defer cancel()

	var (
		chain [][]byte
		key   *ecdsa.PrivateKey
		err   error
	)
	if m.cfg.DryRun {
		chain, key, err = selfSigned(r.Domains)
	} else {
		chain, key, err = m.obtain(ctx, r)
	}
	if err != nil {
		return Info{}, err
	}

	info, err := m.store(r.Name, chain, key)
	if err != nil {
		return Info{}, err
	}
	slog.Info("certificate stored", "name", r.Name, "domains", info.Domains, "expires", info.NotAfter, "dry_run", m.cfg.DryRun)
	return info, nil
}

// Info returns the stored certificate, or nil when there is none.
func (m *Manager) Info(name string) (*Info, error) {
	if err := validate.Name(name); err != nil {
		return nil, err
	}
	pemData, err := os.ReadFile(filepath.Join(m.certDir(name), "fullchain.pem"))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	block, _ := pem.Decode(pemData)
	if block == nil {
		return nil, errors.New("stored certificate is not valid PEM")
	}
	leaf, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, err
	}
	info := describe(name, leaf)
	return &info, nil
}

// Remove deletes a stored certificate. Removing a missing one succeeds.
func (m *Manager) Remove(name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	return os.RemoveAll(m.certDir(name))
}

func (m *Manager) obtain(ctx context.Context, r Request) ([][]byte, *ecdsa.PrivateKey, error) {
	directory, label := m.cfg.ProductionURL, "production"
	if r.Staging {
		directory, label = m.cfg.StagingURL, "staging"
	}

	accountKey, err := m.accountKey(label)
	if err != nil {
		return nil, nil, err
	}
	client := &acme.Client{Key: accountKey, DirectoryURL: directory, HTTPClient: m.cfg.HTTPClient, UserAgent: m.cfg.UserAgent}

	account := &acme.Account{}
	if r.Email != "" {
		account.Contact = []string{"mailto:" + r.Email}
	}
	if _, err := client.Register(ctx, account, acme.AcceptTOS); err != nil && !errors.Is(err, acme.ErrAccountAlreadyExists) {
		return nil, nil, fmt.Errorf("could not register with Let's Encrypt: %w", err)
	}

	order, err := client.AuthorizeOrder(ctx, acme.DomainIDs(r.Domains...))
	if err != nil {
		return nil, nil, fmt.Errorf("Let's Encrypt refused the order: %s", describeError(err))
	}

	for _, authzURL := range order.AuthzURLs {
		if err := m.authorize(ctx, client, authzURL); err != nil {
			return nil, nil, err
		}
	}

	if order, err = client.WaitOrder(ctx, order.URI); err != nil {
		return nil, nil, fmt.Errorf("the order did not become ready: %s", describeError(err))
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{DNSNames: r.Domains}, key)
	if err != nil {
		return nil, nil, err
	}
	chain, _, err := client.CreateOrderCert(ctx, order.FinalizeURL, csr, true)
	if err != nil {
		return nil, nil, fmt.Errorf("could not finalise the order: %s", describeError(err))
	}
	return chain, key, nil
}

// authorize proves control of one domain by publishing the HTTP-01 token
// where nginx serves it.
func (m *Manager) authorize(ctx context.Context, client *acme.Client, authzURL string) error {
	authz, err := client.GetAuthorization(ctx, authzURL)
	if err != nil {
		return fmt.Errorf("could not read the authorization: %s", describeError(err))
	}
	if authz.Status == acme.StatusValid {
		return nil
	}

	var challenge *acme.Challenge
	for _, c := range authz.Challenges {
		if c.Type == "http-01" {
			challenge = c
		}
	}
	if challenge == nil {
		return fmt.Errorf("Let's Encrypt offered no HTTP-01 challenge for %s", authz.Identifier.Value)
	}
	// The token comes from the CA; never let it choose a path.
	if !tokenRe.MatchString(challenge.Token) {
		return errors.New("Let's Encrypt sent an unexpected challenge token")
	}

	response, err := client.HTTP01ChallengeResponse(challenge.Token)
	if err != nil {
		return err
	}
	file := filepath.Join(m.webroot(), filepath.FromSlash(client.HTTP01ChallengePath(challenge.Token)))
	if err := os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
		return err
	}
	if err := os.WriteFile(file, []byte(response), 0o644); err != nil {
		return err
	}
	defer os.Remove(file)

	if _, err := client.Accept(ctx, challenge); err != nil {
		return fmt.Errorf("could not start validation of %s: %s", authz.Identifier.Value, describeError(err))
	}
	if _, err := client.WaitAuthorization(ctx, authz.URI); err != nil {
		return fmt.Errorf("could not validate %s: %s", authz.Identifier.Value, describeError(err))
	}
	return nil
}

// accountKey loads or creates the ACME account key for one CA.
func (m *Manager) accountKey(label string) (crypto.Signer, error) {
	path := filepath.Join(m.cfg.StateDir, "acme", label+"-account.pem")

	if data, err := os.ReadFile(path); err == nil {
		block, _ := pem.Decode(data)
		if block == nil {
			return nil, errors.New("the stored ACME account key is not valid PEM")
		}
		return x509.ParseECPrivateKey(block.Bytes)
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, err
	}
	if err := writeFile(path, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der}), 0o600, 0o700); err != nil {
		return nil, err
	}
	return key, nil
}

func (m *Manager) store(name string, chain [][]byte, key *ecdsa.PrivateKey) (Info, error) {
	leaf, err := x509.ParseCertificate(chain[0])
	if err != nil {
		return Info{}, fmt.Errorf("the issued certificate is not valid: %w", err)
	}

	var fullchain bytes.Buffer
	for _, der := range chain {
		if err := pem.Encode(&fullchain, &pem.Block{Type: "CERTIFICATE", Bytes: der}); err != nil {
			return Info{}, err
		}
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return Info{}, err
	}

	dir := m.certDir(name)
	// Key first, so a certificate is never present without its key.
	if err := writeFile(filepath.Join(dir, "privkey.pem"), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600, 0o700); err != nil {
		return Info{}, err
	}
	if err := writeFile(filepath.Join(dir, "fullchain.pem"), fullchain.Bytes(), 0o600, 0o700); err != nil {
		return Info{}, err
	}
	return describe(name, leaf), nil
}

func describe(name string, leaf *x509.Certificate) Info {
	return Info{
		Name:      name,
		Domains:   leaf.DNSNames,
		NotBefore: leaf.NotBefore.UTC(),
		NotAfter:  leaf.NotAfter.UTC(),
		Issuer:    leaf.Issuer.CommonName,
		Serial:    leaf.SerialNumber.Text(16),
	}
}

// describeError turns the ACME library's errors into messages a user can act on.
func describeError(err error) string {
	var authz *acme.AuthorizationError
	if errors.As(err, &authz) {
		var details []string
		for _, problem := range authz.Errors {
			details = append(details, problem.Error())
		}
		if len(details) > 0 {
			return strings.Join(details, "; ")
		}
	}
	var apiErr *acme.Error
	if errors.As(err, &apiErr) && apiErr.Detail != "" {
		return apiErr.Detail
	}
	return err.Error()
}

// selfSigned makes a throwaway certificate for dry-run mode.
func selfSigned(domains []string) ([][]byte, *ecdsa.PrivateKey, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 100))
	if err != nil {
		return nil, nil, err
	}
	template := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: domains[0]},
		DNSNames:     domains,
		NotBefore:    time.Now().Add(-time.Minute),
		NotAfter:     time.Now().Add(90 * 24 * time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	// Only the parent's subject is used as the issuer name.
	parent := &x509.Certificate{Subject: pkix.Name{CommonName: "CommyPanel dry run"}}
	der, err := x509.CreateCertificate(rand.Reader, template, parent, &key.PublicKey, key)
	if err != nil {
		return nil, nil, err
	}
	return [][]byte{der}, key, nil
}

// writeFile writes atomically: a temp file in the same directory, then rename.
func writeFile(path string, data []byte, mode, dirMode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), dirMode); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}
