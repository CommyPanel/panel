package server

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"commypanel/agent/internal/dbremote"
	"commypanel/agent/internal/dbtls"
	"commypanel/agent/internal/validate"
)

type fakeRemote struct {
	opened []dbremote.OpenRequest
	closed []string
	err    error
}

func (f *fakeRemote) Open(_ context.Context, r dbremote.OpenRequest) (int, error) {
	if f.err != nil {
		return 0, f.err
	}
	f.opened = append(f.opened, r)
	return r.Port, nil
}
func (f *fakeRemote) Close(s string) error { f.closed = append(f.closed, s); return f.err }
func (f *fakeRemote) List() []dbremote.Info {
	return []dbremote.Info{{Server: "x", Port: 23456, Allow: []string{"198.51.100.7/32"}, ExpiresAt: 0, Connections: 2, Total: 17}}
}

type fakeTLS struct {
	got struct {
		server, engine string
		hosts          []string
	}
	err error
}

func (f *fakeTLS) Ensure(server, engine string, hosts []string) (dbtls.Result, error) {
	f.got.server, f.got.engine, f.got.hosts = server, engine, hosts
	return dbtls.Result{CAPEM: "-----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----\n", Changed: true}, f.err
}

func remoteServer(r DBRemote, c DBTLS) *Server {
	return New(Options{Nginx: &fakeNginx{}, DBRemote: r, DBTLS: c, Authorize: AllowAll(), Version: "test"})
}

func TestDatabaseTLSEndpoint(t *testing.T) {
	f := &fakeTLS{}
	s := remoteServer(nil, f)
	rec := do(t, s, "POST", "/v1/databases/tls", `{"server":"main","engine":"mariadb","hosts":["203.0.113.5","panel.example.com"]}`)
	if rec.Code != 200 {
		t.Fatalf("code %d %s", rec.Code, rec.Body)
	}
	var out map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	if out["changed"] != true || !strings.HasPrefix(out["ca_pem"].(string), "-----BEGIN CERTIFICATE-----") || len(out) != 2 {
		t.Errorf("body = %v", out)
	}
	if f.got.server != "main" || f.got.engine != "mariadb" || len(f.got.hosts) != 2 {
		t.Errorf("got %+v", f.got)
	}
	f.err = &validate.InputError{Msg: "invalid host"}
	if rec := do(t, s, "POST", "/v1/databases/tls", `{"server":"main","engine":"mariadb","hosts":["*"]}`); rec.Code != 400 {
		t.Errorf("code %d", rec.Code)
	}
	if rec := do(t, New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll()}), "POST", "/v1/databases/tls", `{}`); rec.Code != http.StatusNotImplemented {
		t.Errorf("without a manager: %d", rec.Code)
	}
}

func TestRemoteOpenCloseList(t *testing.T) {
	f := &fakeRemote{}
	s := remoteServer(f, nil)
	rec := do(t, s, "POST", "/v1/databases/remote/open", `{"server":"x","engine":"postgres","port":23456,"allow":["198.51.100.7/32"],"expires_at":1767225600}`)
	if rec.Code != 200 || strings.TrimSpace(rec.Body.String()) != `{"port":23456}` {
		t.Fatalf("open: %d %s", rec.Code, rec.Body)
	}
	if o := f.opened[0]; o.Server != "x" || o.Engine != "postgres" || o.Port != 23456 || o.ExpiresAt != 1767225600 || o.Allow[0] != "198.51.100.7/32" {
		t.Errorf("opened %+v", o)
	}
	rec = do(t, s, "POST", "/v1/databases/remote/close", `{"server":"x"}`)
	if rec.Code != 200 || strings.TrimSpace(rec.Body.String()) != `{}` || f.closed[0] != "x" {
		t.Errorf("close: %d %s", rec.Code, rec.Body)
	}
	rec = do(t, s, "GET", "/v1/databases/remote", "")
	if rec.Code != 200 || strings.TrimSpace(rec.Body.String()) != `{"listeners":[{"server":"x","port":23456,"allow":["198.51.100.7/32"],"expires_at":0,"connections":2,"total":17}]}` {
		t.Errorf("list: %d %s", rec.Code, rec.Body)
	}
}

func TestRemoteOpenErrors(t *testing.T) {
	f := &fakeRemote{err: dbremote.ErrPortInUse}
	s := remoteServer(f, nil)
	rec := do(t, s, "POST", "/v1/databases/remote/open", `{"server":"x","engine":"postgres","port":23456,"allow":["198.51.100.7"]}`)
	if rec.Code != http.StatusConflict || strings.TrimSpace(rec.Body.String()) != `{"error":"port in use"}` {
		t.Errorf("busy port: %d %s", rec.Code, rec.Body)
	}
	f.err = &validate.InputError{Msg: "bad"}
	if rec := do(t, s, "POST", "/v1/databases/remote/open", `{}`); rec.Code != 400 {
		t.Errorf("invalid: %d", rec.Code)
	}
	if rec := do(t, s, "POST", "/v1/databases/remote/open", `not json`); rec.Code != 400 {
		t.Errorf("bad json: %d", rec.Code)
	}
}

func TestRemoteIsNotImplementedWhenUnavailable(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, Authorize: AllowAll()})
	for _, c := range [][2]string{{"POST", "/v1/databases/remote/open"}, {"POST", "/v1/databases/remote/close"}, {"GET", "/v1/databases/remote"}} {
		rec := do(t, s, c[0], c[1], `{}`)
		if rec.Code != http.StatusNotImplemented || strings.TrimSpace(rec.Body.String()) != `{"error":"remote access is not available"}` {
			t.Errorf("%s: %d %s", c[1], rec.Code, rec.Body)
		}
	}
}

func TestRemoteRequiresAuthorization(t *testing.T) {
	s := New(Options{Nginx: &fakeNginx{}, DBRemote: &fakeRemote{}})
	if rec := do(t, s, "GET", "/v1/databases/remote", ""); rec.Code != http.StatusForbidden {
		t.Errorf("code %d", rec.Code)
	}
}
