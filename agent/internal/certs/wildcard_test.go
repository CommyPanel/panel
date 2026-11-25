package certs

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"commypanel/agent/internal/dnsprovider"
	"commypanel/agent/internal/validate"
)

const cfToken = "cftoken_abcdefghijklmnopqrstuvwxyz0123"

// fakeCF is a small Cloudflare API: zones by name, TXT records created and deleted, every call logged.
type fakeCF struct {
	t   *testing.T
	srv *httptest.Server

	mu      sync.Mutex
	zones   map[string]string // zone name -> id
	records map[string]cfRecord
	next    int
	calls   []string // "METHOD path"
	events  []string // "create <name>" / "delete <name>"

	status      int // answer every call with this status (0: normal)
	forbidList  bool
	redirectTo  string
	sawBearerOn []string
}

type cfRecord struct{ zone, name, content string }

func newFakeCF(t *testing.T, zones ...string) *fakeCF {
	t.Helper()
	f := &fakeCF{t: t, zones: map[string]string{}, records: map[string]cfRecord{}}
	for i, z := range zones {
		f.zones[z] = fmt.Sprintf("zone%d", i+1)
	}
	f.srv = httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeCF) url() string { return f.srv.URL + "/client/v4" }

func (f *fakeCF) reply(w http.ResponseWriter, status int, success bool, result any, code int) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	body := map[string]any{"success": success, "result": result}
	if code != 0 {
		body["errors"] = []map[string]any{{"code": code, "message": "secret detail " + cfToken}}
	}
	_ = json.NewEncoder(w).Encode(body)
}

func (f *fakeCF) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.calls = append(f.calls, r.Method+" "+r.URL.Path+"?"+r.URL.RawQuery)
	if f.redirectTo != "" {
		http.Redirect(w, r, f.redirectTo, http.StatusFound)
		return
	}
	if r.Header.Get("Authorization") != "Bearer "+cfToken {
		f.reply(w, http.StatusUnauthorized, false, nil, 9109)
		return
	}
	if f.status != 0 {
		f.reply(w, f.status, false, nil, 0)
		return
	}
	path := strings.TrimPrefix(r.URL.Path, "/client/v4")

	switch {
	case r.Method == "GET" && path == "/zones":
		name := r.URL.Query().Get("name")
		var out []map[string]string
		if id, ok := f.zones[name]; ok {
			out = append(out, map[string]string{"id": id, "name": name, "status": "active"})
		}
		f.reply(w, 200, true, append([]map[string]string{}, out...), 0)
	case r.Method == "GET" && strings.HasSuffix(path, "/dns_records"):
		if f.forbidList {
			f.reply(w, http.StatusForbidden, false, nil, 10000)
			return
		}
		f.reply(w, 200, true, []any{}, 0)
	case r.Method == "POST" && strings.HasSuffix(path, "/dns_records"):
		var body struct {
			Type, Name, Content string
			TTL                 int
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body.Type != "TXT" || !strings.HasPrefix(body.Name, "_acme-challenge.") || body.TTL != 120 {
			f.t.Errorf("unexpected record: %+v", body)
		}
		f.next++
		id := fmt.Sprintf("rec%d", f.next)
		f.records[id] = cfRecord{zone: strings.Split(path, "/")[2], name: body.Name, content: body.Content}
		f.events = append(f.events, "create "+body.Name)
		f.reply(w, 200, true, map[string]string{"id": id}, 0)
	case r.Method == "DELETE" && strings.Contains(path, "/dns_records/"):
		id := path[strings.LastIndex(path, "/")+1:]
		rec, ok := f.records[id]
		if !ok {
			f.reply(w, 404, false, nil, 81044)
			return
		}
		delete(f.records, id)
		f.events = append(f.events, "delete "+rec.name)
		f.reply(w, 200, true, map[string]string{"id": id}, 0)
	default:
		f.t.Errorf("unexpected call %s %s", r.Method, r.URL)
		http.NotFound(w, r)
	}
}

// txt is where the fake CA looks for records.
func (f *fakeCF) txt(name string) []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []string
	for _, rec := range f.records {
		if rec.name == name {
			out = append(out, rec.content)
		}
	}
	return out
}

func (f *fakeCF) left() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return len(f.records)
}

func (f *fakeCF) eventLog() string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return strings.Join(f.events, ", ")
}

func wildSetup(t *testing.T, zones ...string) (*Manager, *fakeCA, *fakeCF, string) {
	t.Helper()
	dir := t.TempDir()
	ca := newFakeCA(t, filepath.Join(dir, "acme-webroot"))
	cf := newFakeCF(t, zones...)
	ca.txtLookup = cf.txt
	m := NewManager(Config{
		DataDir: dir, StateDir: dir, ProductionURL: ca.directoryURL(), StagingURL: "http://127.0.0.1:1/never",
		Timeout: 30 * time.Second, CloudflareURL: cf.url(), NoPropagation: true,
	})
	return m, ca, cf, dir
}

func wildRequest(domains ...string) Request {
	r := request(domains...)
	r.DNS = &DNSConfig{Provider: "cloudflare", Token: cfToken}
	return r
}

func TestWildcardIssuedThroughCloudflareAndTheRecordsAreRemoved(t *testing.T) {
	m, _, cf, dir := wildSetup(t, "example.com")

	info, err := m.Issue(context.Background(), wildRequest("*.example.com", "example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(info.Domains, ",") != "*.example.com,example.com" {
		t.Errorf("domains = %v", info.Domains)
	}
	if cf.eventLog() != "create _acme-challenge.example.com, delete _acme-challenge.example.com" || cf.left() != 0 {
		t.Errorf("records must be created for the wildcard and removed afterwards: %s (left %d)", cf.eventLog(), cf.left())
	}
	// The apex name in the same order still goes through HTTP-01, and leaves nothing behind.
	if left, _ := filepath.Glob(filepath.Join(dir, "acme-webroot", ".well-known", "acme-challenge", "*")); len(left) != 0 {
		t.Errorf("challenge files left behind: %v", left)
	}
	stored, _ := m.Info("shop")
	if stored == nil || strings.Join(stored.Domains, ",") != "*.example.com,example.com" {
		t.Errorf("stored = %+v", stored)
	}
}

func TestAMixedOrderUsesDNSForTheWildcardAndHTTPForTheRest(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")

	if _, err := m.Issue(context.Background(), wildRequest("shop.example.com", "*.example.com", "www.shop.example.com")); err != nil {
		t.Fatal(err)
	}
	if got := cf.eventLog(); strings.Count(got, "create") != 1 {
		t.Errorf("only the wildcard needs a DNS record: %s", got)
	}
}

func TestDNSRecordsAreRemovedWhenValidationFails(t *testing.T) {
	m, ca, cf, dir := wildSetup(t, "example.com")
	ca.ignoreDNS = true

	_, err := m.Issue(context.Background(), wildRequest("*.example.com"))
	if err == nil || !strings.Contains(err.Error(), "NXDOMAIN") {
		t.Fatalf("want the CA's reason, got %v", err)
	}
	if cf.left() != 0 || !strings.Contains(cf.eventLog(), "delete") {
		t.Errorf("the record must be removed after a failure: %s (left %d)", cf.eventLog(), cf.left())
	}
	if _, statErr := os.Stat(filepath.Join(dir, "certs", "shop")); !os.IsNotExist(statErr) {
		t.Error("nothing may be stored")
	}
}

func TestDNSRecordsAreRemovedWhenTheRequestIsCancelled(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")
	// A propagation wait that ends the request: the record exists by then.
	m.cfg.NoPropagation, m.cfg.Propagation = false, time.Hour
	ctx, cancel := context.WithTimeout(context.Background(), 400*time.Millisecond)
	defer cancel()

	if _, err := m.Issue(ctx, wildRequest("*.example.com")); err == nil {
		t.Fatal("want the cancelled request to fail")
	}
	if cf.left() != 0 || !strings.Contains(cf.eventLog(), "delete") {
		t.Errorf("a cancelled request must still remove its record: %s (left %d)", cf.eventLog(), cf.left())
	}
}

func TestTheZoneIsFoundByWalkingUpFromTheName(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.co.uk")

	if _, err := m.Issue(context.Background(), wildRequest("*.a.b.example.co.uk")); err != nil {
		t.Fatal(err)
	}
	var asked []string
	for _, c := range cf.calls {
		if strings.HasPrefix(c, "GET /client/v4/zones?name=") {
			asked = append(asked, strings.TrimSuffix(strings.SplitN(c, "name=", 2)[1], "&status=active"))
		}
	}
	if strings.Join(asked, " ") != "a.b.example.co.uk b.example.co.uk example.co.uk" {
		t.Errorf("zone lookups = %v", asked)
	}
}

func TestAWildcardNeedsDNSAndAKnownProviderAndATokenOfTheRightShape(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")

	cases := map[string]Request{
		"no dns":            request("*.example.com"),
		"other provider":    {Name: "shop", Domains: []string{"*.example.com"}, AgreeTOS: true, DNS: &DNSConfig{Provider: "route53", Token: cfToken}},
		"short token":       {Name: "shop", Domains: []string{"*.example.com"}, AgreeTOS: true, DNS: &DNSConfig{Provider: "cloudflare", Token: "short"}},
		"token with spaces": {Name: "shop", Domains: []string{"*.example.com"}, AgreeTOS: true, DNS: &DNSConfig{Provider: "cloudflare", Token: strings.Repeat("a b", 10)}},
		"bare tld":          wildRequest("*.com"),
		"star inside":       wildRequest("a.*.example.com"),
	}
	for name, req := range cases {
		_, err := m.Issue(context.Background(), req)
		var ie *validate.InputError
		if !errors.As(err, &ie) {
			t.Errorf("%s: want an input error, got %v", name, err)
		}
	}
	if len(cf.calls) != 0 {
		t.Errorf("invalid requests must not reach Cloudflare: %v", cf.calls)
	}
}

func TestTheTokenNeverAppearsInAnErrorOrWhenPrinted(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")
	cf.status = http.StatusForbidden

	_, err := m.Issue(context.Background(), wildRequest("*.example.com"))
	if err == nil || !strings.Contains(err.Error(), "Zone: Read") {
		t.Fatalf("want a readable permission sentence, got %v", err)
	}
	if strings.Contains(err.Error(), cfToken) || strings.Contains(err.Error(), "secret detail") {
		t.Errorf("the error shows the token or Cloudflare's raw text: %v", err)
	}

	req := wildRequest("*.example.com")
	for _, printed := range []string{fmt.Sprintf("%v", req.DNS), fmt.Sprintf("%+v", *req.DNS), fmt.Sprintf("%#v", req.DNS), fmt.Sprint(req.DNS)} {
		if strings.Contains(printed, cfToken) {
			t.Errorf("printing the DNS settings shows the token: %s", printed)
		}
	}

	// A wrong token (Cloudflare answers 401 with the token in its own text) is a readable sentence too.
	wrong := wildRequest("*.example.com")
	wrong.DNS.Token = "wrongtoken_abcdefghijklmnopqrstuvwxyz0123"
	cf.status = 0
	_, err = m.Issue(context.Background(), wrong)
	if err == nil || strings.Contains(err.Error(), wrong.DNS.Token) || !strings.Contains(err.Error(), "does not accept this token") {
		t.Errorf("wrong token: %v", err)
	}
}

func TestCloudflareErrorsAreSentences(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")
	for status, want := range map[int]string{429: "too many requests", 500: "having trouble", 401: "does not accept this token"} {
		cf.status = status
		_, err := m.Issue(context.Background(), wildRequest("*.example.com"))
		if err == nil || !strings.Contains(err.Error(), want) || strings.Contains(err.Error(), "{") {
			t.Errorf("status %d: %v", status, err)
		}
	}
	cf.status = 0
	_, err := m.Issue(context.Background(), wildRequest("*.nozone.org"))
	if err == nil || !strings.Contains(err.Error(), "no active zone") {
		t.Errorf("unknown zone: %v", err)
	}
}

func TestCloudflareNeverFollowsARedirect(t *testing.T) {
	var hits []string
	other := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits = append(hits, r.Header.Get("Authorization"))
	}))
	defer other.Close()

	cf := newFakeCF(t, "example.com")
	cf.redirectTo = other.URL + "/steal"

	prov, err := dnsprovider.NewCloudflare(dnsprovider.Config{Token: cfToken, BaseURL: cf.url()})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := prov.Present(context.Background(), "example.com", "value"); err == nil || !strings.Contains(err.Error(), "redirect") {
		t.Errorf("want a refusal, got %v", err)
	}
	if len(hits) != 0 {
		t.Errorf("the token was sent to another address: %v", hits)
	}
}

func TestTheProviderOnlyMakesChallengeRecordsAndOnlyDeletesItsOwn(t *testing.T) {
	cf := newFakeCF(t, "example.com")
	prov, _ := dnsprovider.NewCloudflare(dnsprovider.Config{Token: cfToken, BaseURL: cf.url()})

	for _, bad := range []string{"", "*.example.com", "exa mple.com", "example.com\"; evil", "ex\nample.com"} {
		if _, err := prov.Present(context.Background(), bad, "v"); err == nil {
			t.Errorf("name %q must be refused", bad)
		}
	}
	if _, err := prov.Present(context.Background(), "example.com", "bad\nvalue"); err == nil {
		t.Error("a value with a line break must be refused")
	}

	cf.mu.Lock()
	cf.records["foreign"] = cfRecord{zone: "zone1", name: "www.example.com", content: "keep"}
	cf.mu.Unlock()
	if err := prov.Cleanup(context.Background(), dnsprovider.Record{Zone: "zone1", ID: "foreign"}); err != nil {
		t.Fatal(err)
	}
	if cf.left() != 1 {
		t.Error("a record the provider did not make must never be deleted")
	}
}

func TestCheckCloudflare(t *testing.T) {
	m, _, cf, _ := wildSetup(t, "example.com")

	zone, err := m.CheckCloudflare(context.Background(), cfToken, "*.shop.example.com")
	if err != nil || zone != "example.com" {
		t.Fatalf("zone = %q, err = %v", zone, err)
	}
	if cf.left() != 0 || strings.Contains(cf.eventLog(), "create") {
		t.Error("a check must change nothing")
	}

	var ie *validate.InputError
	if _, err := m.CheckCloudflare(context.Background(), cfToken, "shop.other.org"); !errors.As(err, &ie) || !strings.Contains(err.Error(), "no active zone") {
		t.Errorf("unknown zone: %v", err)
	}
	cf.forbidList = true
	if _, err := m.CheckCloudflare(context.Background(), cfToken, "example.com"); !errors.As(err, &ie) || !strings.Contains(err.Error(), "not allowed") {
		t.Errorf("no DNS rights: %v", err)
	}
	cf.forbidList = false
	if _, err := m.CheckCloudflare(context.Background(), "short", "example.com"); !errors.As(err, &ie) {
		t.Errorf("bad token: %v", err)
	}
	if _, err := m.CheckCloudflare(context.Background(), cfToken, "not a domain"); !errors.As(err, &ie) {
		t.Errorf("bad domain: %v", err)
	}
	cf.status = 500
	if _, err := m.CheckCloudflare(context.Background(), cfToken, "example.com"); err == nil || errors.As(err, &ie) {
		t.Errorf("a Cloudflare outage is not the person's mistake: %v", err)
	}
}

// ---- manual DNS steps ----

func prepare(domains ...string) Request {
	r := request(domains...)
	r.Email = ""
	return r
}

func addTXT(cf *fakeCF, recs []Record) {
	cf.mu.Lock()
	defer cf.mu.Unlock()
	for _, r := range recs {
		cf.next++
		cf.records[fmt.Sprintf("manual%d", cf.next)] = cfRecord{name: r.Name, content: r.Value}
	}
}

func TestManualStepsPrepareThenComplete(t *testing.T) {
	m, ca, cf, dir := wildSetup(t)

	got, err := m.Prepare(context.Background(), prepare("*.example.com", "shop.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if len(got.Records) != 1 || got.Records[0].Name != "_acme-challenge.example.com" || len(got.Records[0].Value) != 43 || !got.ExpiresAt.After(time.Now()) {
		t.Fatalf("prepared = %+v", got)
	}
	file := filepath.Join(dir, "acme-pending", "shop.json")
	st, err := os.Stat(file)
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("pending file: %v %v", st, err)
	}
	if d, _ := os.Stat(filepath.Dir(file)); d.Mode().Perm() != 0o700 {
		t.Errorf("pending dir mode = %v", d.Mode().Perm())
	}
	if _, err := m.Info("shop"); err != nil {
		t.Fatal(err)
	}
	if info, _ := m.Info("shop"); info != nil {
		t.Error("nothing is issued before the records exist")
	}

	addTXT(cf, got.Records)
	info, err := m.Complete(context.Background(), "shop")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(info.Domains, ",") != "*.example.com,shop.example.com" {
		t.Errorf("domains = %v", info.Domains)
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Error("the prepared order must be removed after a success")
	}
	if ca.orders != 1 {
		t.Errorf("orders = %d", ca.orders)
	}
}

func TestPrepareAgainWhileTheOrderIsOpenReturnsTheSameRecords(t *testing.T) {
	m, ca, _, _ := wildSetup(t)

	first, err := m.Prepare(context.Background(), prepare("*.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	second, err := m.Prepare(context.Background(), prepare("*.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if first.Records[0] != second.Records[0] || ca.orders != 1 {
		t.Errorf("the same request must not open a second order: %+v %+v orders=%d", first, second, ca.orders)
	}

	third, err := m.Prepare(context.Background(), prepare("*.example.com", "*.other.example.com"))
	if err != nil {
		t.Fatal(err)
	}
	if ca.orders != 2 || len(third.Records) != 2 {
		t.Errorf("other domains need a new order: %+v orders=%d", third, ca.orders)
	}
}

func TestACompletedFailureSpendsThePreparedOrder(t *testing.T) {
	m, _, _, dir := wildSetup(t)

	if _, err := m.Prepare(context.Background(), prepare("*.example.com")); err != nil {
		t.Fatal(err)
	}
	// The person did not create the record.
	if _, err := m.Complete(context.Background(), "shop"); err == nil || !strings.Contains(err.Error(), "NXDOMAIN") {
		t.Fatalf("want the CA's reason, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "acme-pending", "shop.json")); !os.IsNotExist(err) {
		t.Error("a failed order is spent and must be removed")
	}
	if _, err := m.Complete(context.Background(), "shop"); !errors.Is(err, ErrNoPending) {
		t.Errorf("want ErrNoPending, got %v", err)
	}
}

func TestCompleteWithoutPrepareAndExpiredAndRemoved(t *testing.T) {
	m, _, _, dir := wildSetup(t)

	if _, err := m.Complete(context.Background(), "shop"); !errors.Is(err, ErrNoPending) {
		t.Errorf("never prepared: %v", err)
	}
	if _, err := m.Complete(context.Background(), "../x"); err == nil {
		t.Error("a bad name must be refused")
	}

	if _, err := m.Prepare(context.Background(), prepare("*.example.com")); err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(dir, "acme-pending", "shop.json")
	raw, _ := os.ReadFile(file)
	var p map[string]any
	_ = json.Unmarshal(raw, &p)
	p["expires_at"] = time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)
	raw, _ = json.Marshal(p)
	_ = os.WriteFile(file, raw, 0o600)
	if _, err := m.Complete(context.Background(), "shop"); !errors.Is(err, ErrNoPending) {
		t.Errorf("expired: %v", err)
	}

	if _, err := m.Prepare(context.Background(), prepare("*.example.com")); err != nil {
		t.Fatal(err)
	}
	if err := m.RemovePending("shop"); err != nil {
		t.Fatal(err)
	}
	if err := m.RemovePending("shop"); err != nil {
		t.Errorf("removing a missing one is fine: %v", err)
	}
	if err := m.RemovePending("../x"); err == nil {
		t.Error("a bad name must be refused")
	}
	if _, err := os.Stat(file); !os.IsNotExist(err) {
		t.Error("the prepared order must be gone")
	}
}

func TestPrepareRefusesWhatHasNoWildcardOrIsInvalid(t *testing.T) {
	m, ca, _, _ := wildSetup(t)
	for name, req := range map[string]Request{
		"no wildcard": prepare("shop.example.com"),
		"no tos":      {Name: "shop", Domains: []string{"*.example.com"}},
		"bad domain":  prepare("*.com"),
		"bad name":    {Name: "../x", Domains: []string{"*.example.com"}, AgreeTOS: true},
	} {
		var ie *validate.InputError
		if _, err := m.Prepare(context.Background(), req); !errors.As(err, &ie) {
			t.Errorf("%s: want an input error, got %v", name, err)
		}
	}
	if ca.orders != 0 {
		t.Error("invalid requests must not reach the CA")
	}
}

func TestDryRunManualStepsAndCheckWorkOffline(t *testing.T) {
	dir := t.TempDir()
	m := NewManager(Config{DataDir: dir, StateDir: dir, DryRun: true, HTTPClient: &http.Client{Transport: noNetwork{t}}})

	got, err := m.Prepare(context.Background(), prepare("*.example.com"))
	if err != nil || len(got.Records) != 1 || got.Records[0].Name != "_acme-challenge.example.com" {
		t.Fatalf("prepared = %+v err = %v", got, err)
	}
	again, _ := m.Prepare(context.Background(), prepare("*.example.com"))
	if again.Records[0] != got.Records[0] {
		t.Error("dry-run records must be deterministic")
	}
	info, err := m.Complete(context.Background(), "shop")
	if err != nil || info.Issuer != "CommyPanel dry run" || info.Domains[0] != "*.example.com" {
		t.Errorf("info = %+v err = %v", info, err)
	}

	zone, err := m.CheckCloudflare(context.Background(), cfToken, "*.a.example.com")
	if err != nil || zone != "example.com" {
		t.Errorf("zone = %q err = %v", zone, err)
	}

	// A wildcard through the DNS provider also works offline in dry-run.
	if _, err := m.Issue(context.Background(), wildRequest("*.example.com")); err != nil {
		t.Errorf("dry-run issue: %v", err)
	}
}
