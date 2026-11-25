package certs

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/acme"
)

// fakeCA is a minimal ACME server (RFC 8555) that validates HTTP-01 challenges
// by looking for the token file in the agent's webroot. It does not verify
// JWS signatures; the client side is what is under test.
type fakeCA struct {
	t       *testing.T
	srv     *httptest.Server
	webroot string

	caKey  *ecdsa.PrivateKey
	caCert *x509.Certificate
	caDER  []byte

	mu          sync.Mutex
	nonce       int
	newAccounts int
	authz       []*fakeAuthz
	finalized   bool
	certChain   []byte
	orders      int

	// Test switches.
	forceToken string // override the token the CA hands out

	// DNS-01: the account key's thumbprint (taken from the registration), and where the CA looks
	// for TXT records. A nil lookup means no record is ever found.
	thumbprint string
	txtLookup  func(name string) []string
	ignoreDNS  bool // find no TXT records even when they exist
}

type fakeAuthz struct {
	domain   string
	ident    string // as ordered, with the "*." of a wildcard
	wildcard bool
	token    string
	status   string
	detail   string
}

func newFakeCA(t *testing.T, webroot string) *fakeCA {
	t.Helper()

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: "Fake CA"},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(24 * time.Hour),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign,
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	cert, _ := x509.ParseCertificate(der)

	ca := &fakeCA{t: t, webroot: webroot, caKey: key, caCert: cert, caDER: der}
	ca.srv = httptest.NewServer(http.HandlerFunc(ca.serve))
	t.Cleanup(ca.srv.Close)
	return ca
}

func (c *fakeCA) directoryURL() string { return c.srv.URL + "/directory" }

func (c *fakeCA) serve(w http.ResponseWriter, r *http.Request) {
	c.mu.Lock()
	defer c.mu.Unlock()

	c.nonce++
	w.Header().Set("Replay-Nonce", fmt.Sprintf("nonce-%d", c.nonce))
	base := c.srv.URL
	path := r.URL.Path

	switch {
	case path == "/directory":
		c.json(w, 200, map[string]string{
			"newNonce":   base + "/nonce",
			"newAccount": base + "/acct-new",
			"newOrder":   base + "/order-new",
			"keyChange":  base + "/key-change",
			"revokeCert": base + "/revoke",
		})
	case path == "/nonce":
		w.WriteHeader(200)
	case path == "/acct-new":
		c.rememberKey(r)
		c.newAccounts++
		w.Header().Set("Location", base+"/acct/1")
		status := 201
		if c.newAccounts > 1 {
			status = 200
		}
		c.json(w, status, map[string]any{"status": "valid"})
	case path == "/order-new":
		c.newOrder(w, r)
	case strings.HasPrefix(path, "/authz/"):
		c.showAuthz(w, path)
	case strings.HasPrefix(path, "/chall/"):
		c.validate(w, path)
	case strings.HasPrefix(path, "/order/"):
		c.json(w, 200, c.orderBody())
	case strings.HasPrefix(path, "/finalize/"):
		c.finalize(w, r)
	case strings.HasPrefix(path, "/cert/"):
		w.Header().Set("Content-Type", "application/pem-certificate-chain")
		w.Write(c.certChain)
	default:
		http.NotFound(w, r)
	}
}

func (c *fakeCA) payload(r *http.Request) []byte {
	var jws struct {
		Payload string `json:"payload"`
	}
	body, _ := io.ReadAll(r.Body)
	_ = json.Unmarshal(body, &jws)
	raw, _ := base64.RawURLEncoding.DecodeString(jws.Payload)
	return raw
}

// rememberKey takes the account key out of the registration request, so DNS-01 values can be checked.
func (c *fakeCA) rememberKey(r *http.Request) {
	var jws struct {
		Protected string `json:"protected"`
	}
	body, _ := io.ReadAll(r.Body)
	_ = json.Unmarshal(body, &jws)
	raw, _ := base64.RawURLEncoding.DecodeString(jws.Protected)
	var header struct {
		JWK struct{ X, Y string } `json:"jwk"`
	}
	_ = json.Unmarshal(raw, &header)
	x, _ := base64.RawURLEncoding.DecodeString(header.JWK.X)
	y, _ := base64.RawURLEncoding.DecodeString(header.JWK.Y)
	pub := &ecdsa.PublicKey{Curve: elliptic.P256(), X: new(big.Int).SetBytes(x), Y: new(big.Int).SetBytes(y)}
	if thumb, err := acme.JWKThumbprint(pub); err == nil {
		c.thumbprint = thumb
	}
}

// expectedTXT is what a correct DNS-01 answer for a token looks like.
func (c *fakeCA) expectedTXT(token string) string {
	sum := sha256.Sum256([]byte(token + "." + c.thumbprint))
	return base64.RawURLEncoding.EncodeToString(sum[:])
}

func (c *fakeCA) newOrder(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Identifiers []struct{ Type, Value string } `json:"identifiers"`
	}
	_ = json.Unmarshal(c.payload(r), &req)

	c.orders++
	c.authz = nil
	c.finalized = false
	for i, id := range req.Identifiers {
		token := fmt.Sprintf("token-%d-%d-abcdefghijklmnopqrstuvwxyz", c.orders, i)
		if c.forceToken != "" {
			token = c.forceToken
		}
		a := &fakeAuthz{domain: id.Value, ident: id.Value, token: token, status: "pending"}
		if strings.HasPrefix(id.Value, "*.") {
			a.domain, a.wildcard = strings.TrimPrefix(id.Value, "*."), true
		}
		c.authz = append(c.authz, a)
	}
	w.Header().Set("Location", c.srv.URL+"/order/"+strconv.Itoa(c.orders))
	c.json(w, 201, c.orderBody())
}

func (c *fakeCA) orderBody() map[string]any {
	status, ready := "ready", true
	for _, a := range c.authz {
		if a.status != "valid" {
			ready = false
		}
	}
	if !ready {
		status = "pending"
	}
	if c.finalized {
		status = "valid"
	}

	var ids []map[string]string
	var urls []string
	for i, a := range c.authz {
		ids = append(ids, map[string]string{"type": "dns", "value": a.ident})
		urls = append(urls, c.srv.URL+"/authz/"+strconv.Itoa(i))
	}
	body := map[string]any{
		"status":         status,
		"expires":        time.Now().Add(time.Hour).Format(time.RFC3339),
		"identifiers":    ids,
		"authorizations": urls,
		"finalize":       c.srv.URL + "/finalize/" + strconv.Itoa(c.orders),
	}
	if c.finalized {
		body["certificate"] = c.srv.URL + "/cert/" + strconv.Itoa(c.orders)
	}
	return body
}

func (c *fakeCA) authzIndex(path string) int {
	i, _ := strconv.Atoi(path[strings.LastIndex(path, "/")+1:])
	return i
}

func (c *fakeCA) showAuthz(w http.ResponseWriter, path string) {
	i := c.authzIndex(path)
	a := c.authz[i]
	kind := "http-01"
	if a.wildcard {
		kind = "dns-01"
	}
	challenge := map[string]any{
		"type": kind, "url": c.srv.URL + "/chall/" + strconv.Itoa(i), "token": a.token, "status": a.status,
	}
	if a.status == "invalid" {
		challenge["error"] = map[string]string{"type": "urn:ietf:params:acme:error:connection", "detail": a.detail}
	}
	body := map[string]any{
		"status":     a.status,
		"expires":    time.Now().Add(time.Hour).Format(time.RFC3339),
		"identifier": map[string]string{"type": "dns", "value": a.domain},
		"challenges": []any{challenge},
	}
	if a.wildcard {
		body["wildcard"] = true
	}
	c.json(w, 200, body)
}

// validate plays Let's Encrypt fetching http://domain/.well-known/acme-challenge/token.
func (c *fakeCA) validate(w http.ResponseWriter, path string) {
	i := c.authzIndex(path)
	a := c.authz[i]

	if a.wildcard {
		c.validateDNS(w, path, a)
		return
	}

	file := filepath.Join(c.webroot, ".well-known", "acme-challenge", a.token)
	if data, err := os.ReadFile(file); err == nil && len(data) > 0 && strings.HasPrefix(string(data), a.token+".") {
		a.status = "valid"
	} else {
		a.status = "invalid"
		a.detail = fmt.Sprintf("Fetching http://%s/.well-known/acme-challenge/%s: Timeout during connect", a.domain, a.token)
	}
	c.json(w, 200, map[string]any{"type": "http-01", "url": c.srv.URL + path, "token": a.token, "status": "processing"})
}

// validateDNS plays Let's Encrypt looking up _acme-challenge.<domain> TXT.
func (c *fakeCA) validateDNS(w http.ResponseWriter, path string, a *fakeAuthz) {
	want := c.expectedTXT(a.token)
	found := false
	if c.txtLookup != nil && !c.ignoreDNS {
		for _, v := range c.txtLookup("_acme-challenge." + a.domain) {
			if v == want {
				found = true
			}
		}
	}
	if found {
		a.status = "valid"
	} else {
		a.status = "invalid"
		a.detail = fmt.Sprintf("DNS problem: NXDOMAIN looking up TXT for _acme-challenge.%s - check that a DNS record exists for this domain", a.domain)
	}
	c.json(w, 200, map[string]any{"type": "dns-01", "url": c.srv.URL + path, "token": a.token, "status": "processing"})
}

func (c *fakeCA) finalize(w http.ResponseWriter, r *http.Request) {
	var req struct {
		CSR string `json:"csr"`
	}
	_ = json.Unmarshal(c.payload(r), &req)
	der, _ := base64.RawURLEncoding.DecodeString(req.CSR)
	csr, err := x509.ParseCertificateRequest(der)
	if err != nil {
		http.Error(w, "bad csr", 400)
		return
	}

	leaf := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: csr.DNSNames[0]},
		DNSNames:     csr.DNSNames,
		NotBefore:    time.Now().Add(-time.Minute),
		NotAfter:     time.Now().Add(90 * 24 * time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, leaf, c.caCert, csr.PublicKey, c.caKey)
	if err != nil {
		c.t.Error(err)
		http.Error(w, "sign failed", 500)
		return
	}
	c.certChain = append(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: leafDER}),
		pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: c.caDER})...)
	c.finalized = true
	c.json(w, 200, c.orderBody())
}

func (c *fakeCA) json(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func (c *fakeCA) accountsCreated() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.newAccounts
}
