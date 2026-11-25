package certs

import (
	"context"
	"crypto/ecdsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"golang.org/x/crypto/acme"

	"commypanel/agent/internal/dnsprovider"
	"commypanel/agent/internal/statedir"
	"commypanel/agent/internal/validate"
)

// ErrNoPending means there is no prepared order to complete (never prepared, spent, or removed).
var ErrNoPending = errors.New("there is no prepared order for that certificate: prepare it again to get new DNS records")

// pendingLife is how long a prepared order is kept when the CA does not say.
const pendingLife = 6 * 24 * time.Hour

// Record is one TXT record the person has to create.
type Record struct {
	Name  string `json:"name"`
	Value string `json:"value"`
}

// Prepared is the answer to Prepare.
type Prepared struct {
	Records   []Record  `json:"records"`
	ExpiresAt time.Time `json:"expires_at"`
}

// pending is what the agent keeps between Prepare and Complete, in <state>/acme-pending/<name>.json.
type pending struct {
	Name      string    `json:"name"`
	Domains   []string  `json:"domains"`
	Email     string    `json:"email,omitempty"`
	Staging   bool      `json:"staging"`
	Order     string    `json:"order"`
	Authz     []string  `json:"authz"`
	Records   []Record  `json:"records"`
	CreatedAt time.Time `json:"created_at"`
	ExpiresAt time.Time `json:"expires_at"`
}

func (m *Manager) pendingDir() string { return filepath.Join(m.cfg.StateDir, "acme-pending") }

func (m *Manager) pendingPath(name string) string {
	return filepath.Join(m.pendingDir(), name+".json")
}

func (m *Manager) loadPending(name string) (*pending, error) {
	raw, err := os.ReadFile(m.pendingPath(name))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var p pending
	if err := json.Unmarshal(raw, &p); err != nil || p.Name != name {
		return nil, nil // not ours or damaged: treated as none
	}
	return &p, nil
}

func (m *Manager) savePending(p *pending) error {
	if err := os.MkdirAll(m.pendingDir(), 0o700); err != nil {
		return err
	}
	if err := statedir.Check(m.pendingDir()); err != nil {
		return err
	}
	raw, err := json.Marshal(p)
	if err != nil {
		return err
	}
	return writeFile(m.pendingPath(p.Name), raw, 0o600, 0o700)
}

func (m *Manager) dropPending(name string) error {
	err := os.Remove(m.pendingPath(name))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}

// validatePrepare checks a request for the manual DNS steps: like an issue request, with at least
// one wildcard name and no DNS provider needed.
func (m *Manager) validatePrepare(r Request) error {
	if err := m.validateBase(r); err != nil {
		return err
	}
	if !hasWildcard(r.Domains) {
		return &validate.InputError{Msg: "manual DNS steps are for certificates with a wildcard name (*.example.com); other names are validated by themselves"}
	}
	return nil
}

// Prepare creates (or finds again) the order for a certificate with wildcard names and says which
// TXT records the person must create. Nothing is issued yet: Complete does that once the records
// exist. The same request while its order is still open returns the same records.
func (m *Manager) Prepare(ctx context.Context, r Request) (Prepared, error) {
	if err := m.validatePrepare(r); err != nil {
		return Prepared{}, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, m.cfg.Timeout)
	defer cancel()

	if m.cfg.DryRun {
		return m.prepareDryRun(r)
	}

	client, err := m.newClient(ctx, r.Staging, r.Email)
	if err != nil {
		return Prepared{}, err
	}

	if p, _ := m.loadPending(r.Name); p != nil && p.Staging == r.Staging && slices.Equal(p.Domains, r.Domains) && time.Now().Before(p.ExpiresAt) {
		if order, err := client.GetOrder(ctx, p.Order); err == nil && (order.Status == acme.StatusPending || order.Status == acme.StatusReady) {
			return Prepared{Records: p.Records, ExpiresAt: p.ExpiresAt}, nil
		}
	}

	order, err := client.AuthorizeOrder(ctx, acme.DomainIDs(r.Domains...))
	if err != nil {
		return Prepared{}, fmt.Errorf("Let's Encrypt refused the order: %s", describeError(err))
	}

	var records []Record
	for _, authzURL := range order.AuthzURLs {
		authz, err := client.GetAuthorization(ctx, authzURL)
		if err != nil {
			return Prepared{}, fmt.Errorf("could not read the authorization: %s", describeError(err))
		}
		if !authz.Wildcard || authz.Status == acme.StatusValid {
			continue
		}
		challenge := findChallenge(authz, "dns-01")
		if challenge == nil {
			return Prepared{}, fmt.Errorf("Let's Encrypt offered no DNS-01 challenge for *.%s", authz.Identifier.Value)
		}
		if !tokenRe.MatchString(challenge.Token) {
			return Prepared{}, errors.New("Let's Encrypt sent an unexpected challenge token")
		}
		value, err := client.DNS01ChallengeRecord(challenge.Token)
		if err != nil {
			return Prepared{}, err
		}
		records = append(records, Record{Name: "_acme-challenge." + authz.Identifier.Value, Value: value})
	}

	expires := order.Expires
	if expires.IsZero() || expires.After(time.Now().Add(pendingLife)) {
		expires = time.Now().Add(pendingLife)
	}
	p := &pending{
		Name: r.Name, Domains: r.Domains, Email: r.Email, Staging: r.Staging,
		Order: order.URI, Authz: order.AuthzURLs, Records: records, CreatedAt: time.Now().UTC(), ExpiresAt: expires.UTC(),
	}
	if err := m.savePending(p); err != nil {
		return Prepared{}, err
	}
	return Prepared{Records: records, ExpiresAt: p.ExpiresAt}, nil
}

func (m *Manager) prepareDryRun(r Request) (Prepared, error) {
	var records []Record
	for _, d := range r.Domains {
		if validate.IsWildcard(d) {
			sum := sha256.Sum256([]byte("dry-run:" + d))
			records = append(records, Record{Name: "_acme-challenge." + d[2:], Value: base64.RawURLEncoding.EncodeToString(sum[:])})
		}
	}
	p := &pending{
		Name: r.Name, Domains: r.Domains, Email: r.Email, Staging: r.Staging, Order: "dry-run",
		Records: records, CreatedAt: time.Now().UTC(), ExpiresAt: time.Now().Add(pendingLife).UTC(),
	}
	if err := m.savePending(p); err != nil {
		return Prepared{}, err
	}
	return Prepared{Records: records, ExpiresAt: p.ExpiresAt}, nil
}

// Complete finishes a prepared order: the CA checks the TXT records the person made (and the
// HTTP-01 token for any plain name in the same order), the certificate is stored and the prepared
// order is deleted. When anything fails the order is spent too, so the person prepares again and
// gets new records.
func (m *Manager) Complete(ctx context.Context, name string) (Info, error) {
	if err := validate.Name(name); err != nil {
		return Info{}, err
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, m.cfg.Timeout)
	defer cancel()

	p, err := m.loadPending(name)
	if err != nil {
		return Info{}, err
	}
	if p == nil || time.Now().After(p.ExpiresAt) {
		_ = m.dropPending(name)
		return Info{}, ErrNoPending
	}

	cert, privkey, err := m.complete(ctx, p)
	if err != nil {
		if dropErr := m.dropPending(name); dropErr != nil {
			slog.Warn("could not remove a spent prepared order", "name", name, "err", dropErr)
		}
		return Info{}, err
	}
	info, err := m.store(name, cert, privkey)
	if err != nil {
		return Info{}, err
	}
	_ = m.dropPending(name)
	slog.Info("certificate stored", "name", name, "domains", info.Domains, "expires", info.NotAfter, "dry_run", m.cfg.DryRun)
	return info, nil
}

// complete asks the CA to validate every name of a prepared order and finalises it.
func (m *Manager) complete(ctx context.Context, p *pending) ([][]byte, *ecdsa.PrivateKey, error) {
	if m.cfg.DryRun {
		return selfSigned(p.Domains)
	}
	client, err := m.newClient(ctx, p.Staging, p.Email)
	if err != nil {
		return nil, nil, err
	}
	for _, authzURL := range p.Authz {
		authz, err := client.GetAuthorization(ctx, authzURL)
		if err != nil {
			return nil, nil, fmt.Errorf("could not read the authorization: %s", describeError(err))
		}
		if authz.Status == acme.StatusValid {
			continue
		}
		if authz.Wildcard {
			challenge := findChallenge(authz, "dns-01")
			if challenge == nil || !tokenRe.MatchString(challenge.Token) {
				return nil, nil, fmt.Errorf("Let's Encrypt offered no usable DNS-01 challenge for *.%s", authz.Identifier.Value)
			}
			if err := m.accept(ctx, client, authz, challenge); err != nil {
				return nil, nil, err
			}
			continue
		}
		if err := m.authorizeHTTP(ctx, client, authz); err != nil {
			return nil, nil, err
		}
	}
	return m.finish(ctx, client, p.Order, p.Domains)
}

// RemovePending forgets a prepared order. A missing one is fine.
func (m *Manager) RemovePending(name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.dropPending(name)
}

// CheckCloudflare says whether a token can find the zone of a domain and read its DNS records. It
// changes nothing and returns the zone's name.
func (m *Manager) CheckCloudflare(ctx context.Context, token, domain string) (string, error) {
	if err := validate.DomainOrWildcard(domain); err != nil {
		return "", err
	}
	if _, err := dnsprovider.NewCloudflare(dnsprovider.Config{Token: token}); err != nil {
		return "", &validate.InputError{Msg: err.Error()}
	}
	if m.cfg.DryRun {
		labels := strings.Split(strings.TrimPrefix(domain, "*."), ".")
		return strings.Join(labels[len(labels)-2:], "."), nil
	}
	cf, err := dnsprovider.NewCloudflare(dnsprovider.Config{Token: token, BaseURL: m.cfg.CloudflareURL})
	if err != nil {
		return "", err
	}
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	zone, err := cf.Check(ctx, domain)
	var problem *dnsprovider.Error
	if errors.As(err, &problem) && problem.Rejected {
		return "", &validate.InputError{Msg: problem.Msg}
	}
	return zone, err
}
