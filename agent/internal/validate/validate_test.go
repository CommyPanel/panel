package validate

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestName(t *testing.T) {
	good := []string{"ab", "my-app", "app1", strings.Repeat("a", 63)}
	bad := []string{"", "a", "-ab", "ab-", "My-App", "a_b", "a b", "a;b", "../etc", "a/b", "a\nb", strings.Repeat("a", 64)}
	for _, s := range good {
		if err := Name(s); err != nil {
			t.Errorf("Name(%q) = %v, want nil", s, err)
		}
	}
	for _, s := range bad {
		if err := Name(s); err == nil {
			t.Errorf("Name(%q) = nil, want error", s)
		}
	}
}

func TestDomain(t *testing.T) {
	good := []string{"example.com", "a.b.example.co.uk", "my-app.example.com", "xn--bcher-kva.example"}
	bad := []string{"", "localhost", "example", ".com", "example..com", "-a.com", "a-.com", "Example.com",
		"exa mple.com", "example.com;", "example.com\nserver_name evil", "a.com/../", "*.example.com",
		strings.Repeat("a", 64) + ".com", strings.Repeat("a.", 130) + "com"}
	for _, s := range good {
		if err := Domain(s); err != nil {
			t.Errorf("Domain(%q) = %v, want nil", s, err)
		}
	}
	for _, s := range bad {
		if err := Domain(s); err == nil {
			t.Errorf("Domain(%q) = nil, want error", s)
		}
	}
}

func TestPort(t *testing.T) {
	for _, p := range []int{1024, 20001, 65535} {
		if err := Port(p); err != nil {
			t.Errorf("Port(%d) = %v", p, err)
		}
	}
	for _, p := range []int{-1, 0, 80, 1023, 65536} {
		if err := Port(p); err == nil {
			t.Errorf("Port(%d) = nil, want error", p)
		}
	}
}

func TestErrorsAreInputErrors(t *testing.T) {
	var ie *InputError
	if !errors.As(Name("BAD"), &ie) {
		t.Fatal("expected *InputError")
	}
}

func TestImage(t *testing.T) {
	good := []string{"mariadb", "mariadb:11.4", "commypanel/my-app:release-12", "library/postgres:16-alpine", "a.b/c_d/e:v1.0"}
	bad := []string{"", "Mariadb", "a b", "img;rm", "img:", "img:-x", "../x", "img@sha256:abc", "a//b", "-a", "a:b:c", strings.Repeat("a", 201)}
	for _, s := range good {
		if err := Image(s); err != nil {
			t.Errorf("Image(%q) = %v", s, err)
		}
	}
	for _, s := range bad {
		if err := Image(s); err == nil {
			t.Errorf("Image(%q) = nil, want error", s)
		}
	}
}

func TestEnv(t *testing.T) {
	for _, k := range []string{"A", "_X", "DB_HOST1"} {
		if err := EnvKey(k); err != nil {
			t.Errorf("EnvKey(%q) = %v", k, err)
		}
	}
	for _, k := range []string{"", "1A", "A=B", "A B", "A\nB", "A-B"} {
		if err := EnvKey(k); err == nil {
			t.Errorf("EnvKey(%q) = nil", k)
		}
	}
	if EnvValue("ok=1 \"quoted\"\nline2") != nil || EnvValue("a\x00b") == nil {
		t.Error("EnvValue rules wrong")
	}
}

func TestPaths(t *testing.T) {
	for _, p := range []string{"/", "/data", "/var/lib/mysql"} {
		if err := ContainerPath(p); err != nil {
			t.Errorf("ContainerPath(%q) = %v", p, err)
		}
	}
	for _, p := range []string{"", "data", "/a/../b", "/a b", "/a;b", "//a"} {
		if err := ContainerPath(p); err == nil {
			t.Errorf("ContainerPath(%q) = nil", p)
		}
	}
	for _, p := range []string{"Dockerfile", "docker/Dockerfile.prod"} {
		if err := RelativePath(p); err != nil {
			t.Errorf("RelativePath(%q) = %v", p, err)
		}
	}
	for _, p := range []string{"", "/etc/passwd", "../Dockerfile", "a/../../b", "-f", "a b"} {
		if err := RelativePath(p); err == nil {
			t.Errorf("RelativePath(%q) = nil", p)
		}
	}
}

func TestResolveUnder(t *testing.T) {
	base := t.TempDir()
	outside := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(base, "escape")); err != nil {
		t.Fatal(err)
	}

	if _, err := ResolveUnder(base, filepath.Join(base, "volumes", "db")); err != nil {
		t.Errorf("descendant rejected: %v", err)
	}
	for name, p := range map[string]string{
		"base itself":    base,
		"parent":         filepath.Dir(base),
		"traversal":      base + "/a/../../etc",
		"relative":       "volumes/db",
		"docker socket":  "/var/run/docker.sock",
		"symlink escape": filepath.Join(base, "escape", "x"),
		"sibling prefix": base + "-evil/x",
	} {
		if _, err := ResolveUnder(base, p); err == nil {
			t.Errorf("%s: %q should be rejected", name, p)
		}
	}
}

func TestNetwork(t *testing.T) {
	for _, ok := range []string{"commypanel", "commypanel-shop", "commypanel-my-app-2"} {
		if err := Network(ok); err != nil {
			t.Errorf("Network(%q) = %v", ok, err)
		}
	}
	for _, bad := range []string{"", "host", "bridge", "none", "default", "container:abc", "commypanel-", "commypanelx", "commypanel_shop", "Commypanel-shop", "commypanel-Shop", "commypanel-shop-", "other-shop", "commypanel-" + strings.Repeat("a", 60), "commypanel-a b", "commypanel/x"} {
		if err := Network(bad); err == nil {
			t.Errorf("Network(%q) must fail", bad)
		}
	}
}

func TestDatabaseImagesAreOfficialAndPinned(t *testing.T) {
	for _, ok := range []string{"mariadb:11.4", "mysql:8.4", "postgres:17", "postgres:17.2", "mariadb:10.11.9"} {
		if err := DatabaseImage(ok); err != nil {
			t.Errorf("%q: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "postgres", "postgres:latest", "postgres:17-alpine", "library/postgres:17", "evil/postgres:17", "postgres:17@sha256:abc", "mongo:7", "postgres:17.1.2.3", "docker.io/postgres:17", "postgres:17 ", "postgres:../x"} {
		if err := DatabaseImage(bad); err == nil {
			t.Errorf("%q must be refused", bad)
		}
	}
}

func TestRunImageIsOursOrAnOfficialDatabase(t *testing.T) {
	for _, ok := range []string{"commypanel/shop:3", "postgres:17"} {
		if err := RunImage(ok); err != nil {
			t.Errorf("%q: %v", ok, err)
		}
	}
	for _, bad := range []string{"alpine:3", "nginx", "ubuntu:24.04", "ghcr.io/evil/x:1", "commypanel", "commypanel/Bad Name:1"} {
		if err := RunImage(bad); err == nil {
			t.Errorf("%q must be refused", bad)
		}
	}
}

func TestDatabaseNamesAndPasswords(t *testing.T) {
	for _, ok := range []string{"shop", "a", "my_app_2", strings.Repeat("a", 31)} {
		if err := DatabaseIdent(ok); err != nil {
			t.Errorf("ident %q: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "1shop", "_x", "Shop", "sh-op", "sh op", "shop;", "sh'op", "sh\"op", "sh`op", "shop\n", "sh$op", strings.Repeat("a", 32), "ünï"} {
		if err := DatabaseIdent(bad); err == nil {
			t.Errorf("ident %q must be refused", bad)
		}
	}
	for _, ok := range []string{strings.Repeat("a", 16), "Abcdefgh12345678", strings.Repeat("Z9", 32)} {
		if err := DatabasePassword(ok); err != nil {
			t.Errorf("password %q: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "short", strings.Repeat("a", 15), strings.Repeat("a", 65), "abcdefgh1234567'", "abcdefgh12345678;", "abcdefgh 2345678x", "abcdefgh1234567\\", "abcdefgh1234567$", "abcdefgh1234567\n"} {
		if err := DatabasePassword(bad); err == nil {
			t.Errorf("password %q must be refused", bad)
		}
	}
	for _, bad := range []string{"", "mongo", "Postgres", "postgres "} {
		if err := DatabaseEngine(bad); err == nil {
			t.Errorf("engine %q must be refused", bad)
		}
	}
	for _, bad := range []string{"", "-a", "a-", "A", "a_b", "a b", strings.Repeat("a", 64)} {
		if err := Alias(bad); err == nil {
			t.Errorf("alias %q must be refused", bad)
		}
	}
}
