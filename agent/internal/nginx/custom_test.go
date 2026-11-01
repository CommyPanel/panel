package nginx

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

func mustCheck(t *testing.T, text string) string {
	t.Helper()
	out, err := CheckCustom(text)
	if err != nil {
		t.Fatalf("CheckCustom(%q): %v", text, err)
	}
	return out
}

func TestCustomAcceptsCommonDirectivesAndKeepsComments(t *testing.T) {
	got := mustCheck(t, `
# Allow big uploads
client_max_body_size 50m;
proxy_read_timeout   120s;   # slow reports
add_header X-Frame-Options "DENY" always;
gzip on;
gzip_types text/css application/javascript;
location /api/ {
  proxy_read_timeout 300s;
  proxy_pass $commypanel_upstream;
}
location = /old { return 301 /new; }
location ~* \.(jpg|png)$ {
  expires 30d;
}
location /admin/ {
  allow 10.0.0.0/8;
  deny all;
}
location /health {
  if ($http_user_agent ~* "bot") { return 403; }
  return 200 "ok";
}
`)
	want := `    # Allow big uploads
    client_max_body_size 50m;
    proxy_read_timeout 120s;
    # slow reports
    add_header X-Frame-Options DENY always;
    gzip on;
    gzip_types text/css application/javascript;
    location /api/ {
        proxy_read_timeout 300s;
        proxy_pass $commypanel_upstream;
    }
    location = /old {
        return 301 /new;
    }
    location ~* "\\.(jpg|png)$" {
        expires 30d;
    }
    location /admin/ {
        allow 10.0.0.0/8;
        deny all;
    }
    location /health {
        if ($http_user_agent ~* bot) {
            return 403;
        }
        return 200 ok;
    }
`
	if got != want {
		t.Errorf("canonical output differs.\n got:\n%s\nwant:\n%s", got, want)
	}
}

func TestCustomEmptyIsFineAndRendersNothing(t *testing.T) {
	for _, in := range []string{"", "  \n\t\n", "\r\n"} {
		if got := mustCheck(t, in); got != "" {
			t.Errorf("CheckCustom(%q) = %q, want nothing", in, got)
		}
	}
}

func TestCustomRefusesWhatCouldReachTheHost(t *testing.T) {
	cases := map[string]string{
		"root":                 "root /;",
		"alias":                "location /f/ { alias /etc/; }",
		"include":              "include /etc/nginx/secrets.conf;",
		"access_log":           "access_log /root/.ssh/authorized_keys;",
		"error_log":            "error_log /etc/cron.d/x;",
		"ssl key":              "ssl_certificate_key /var/lib/commypanel/certs/panel/privkey.pem;",
		"load_module":          "load_module /tmp/evil.so;",
		"listen":               "listen 8080;",
		"server_name":          "server_name evil.example.com;",
		"nested server":        "server { root /; }",
		"http block":           "http { }",
		"upstream":             "upstream x { server 127.0.0.1:2375; }",
		"fastcgi":              "location /x { fastcgi_pass unix:/run/php.sock; }",
		"docker socket":        "location /x { proxy_pass http://unix:/var/run/docker.sock:/; }",
		"foreign proxy":        "location /x { proxy_pass http://127.0.0.1:2375; }",
		"proxy var":            "location /x { proxy_pass $arbitrary; }",
		"lua":                  "content_by_lua_block { os.execute('id') }",
		"perl":                 "perl_set $x 'sub {}';",
		"auth file":            "auth_basic_user_file /etc/shadow;",
		"file test":            "location /x { if (-f /etc/passwd) { return 403; } }",
		"try_files":            "location /x { try_files /etc/passwd =404; }",
		"autoindex":            "location /x { autoindex on; }",
		"client body path":     "client_body_temp_path /etc/;",
		"reserved var":         "set $commypanel_upstream http://169.254.169.254;",
		"set non var":          "set upstream x;",
		"directive in comment": "client_max_body_size 1m; # ; root /;\nroot /;",
	}
	for name, in := range cases {
		out, err := CheckCustom(in)
		var input *validate.InputError
		if err == nil || !errors.As(err, &input) {
			t.Errorf("%s: %q was accepted as %q", name, in, out)
		}
	}
}

func TestCustomRewritesAndIfsAreOnlyAllowedInsideLocations(t *testing.T) {
	// At server level they run before location matching and would capture the
	// Let's Encrypt validation requests.
	for _, in := range []string{
		"return 301 https://example.com$request_uri;",
		"rewrite ^ /x permanent;",
		"if ($host = a) { return 301 /; }",
	} {
		_, err := CheckCustom(in)
		if err == nil || !strings.Contains(err.Error(), "not allowed at the top level") {
			t.Errorf("%q: err = %v", in, err)
		}
	}
	mustCheck(t, "location /a { return 301 /b; rewrite ^/x /y last; }")
}

func TestCustomQuotingCannotSmuggleADirective(t *testing.T) {
	in := `add_header X-Note "a\"; root /etc; #";` + "\n" + `add_header X-Other 'x; include /etc/passwd;';`
	got := mustCheck(t, in)

	// The dangerous text stays inside the quoted values, which are re-escaped.
	if strings.Contains(got, "\n    root") || strings.Contains(got, "\n    include") {
		t.Fatalf("a directive escaped its string:\n%s", got)
	}
	nodes, err := parseCustom(got)
	if err != nil {
		t.Fatal(err)
	}
	for _, n := range nodes {
		if n.name != "add_header" {
			t.Errorf("re-parsing the output found a %q directive", n.name)
		}
	}
}

func TestCustomRefusesControlCharactersAndBadText(t *testing.T) {
	for name, in := range map[string]string{
		"escaped newline": `add_header X "a\nb";`,
		"escaped tab":     `add_header X "a\tb";`,
		"nul":             "add_header X \"a\x00b\";",
		"bell":            "add_header X \"a\x07b\";",
		"line separator":  "add_header X \"a\u2028b\";",
		"invalid utf8":    "add_header X \"\xff\";",
		"lone cr":         "gzip on;\rroot /;",
	} {
		if out, err := CheckCustom(in); err == nil {
			t.Errorf("%s: accepted as %q", name, out)
		}
	}
}

func TestCustomSyntaxErrorsNameTheLine(t *testing.T) {
	cases := map[string]string{
		"gzip on;\nclient_max_body_size 5m\n":            "line 2",
		"gzip on;\n\n}":                                  "line 3: unexpected }",
		"location /a {\n  gzip on;\n":                    "closing }",
		"add_header X \"open;":                           "quote is not closed",
		"add_header X \"a\"b;":                           "after a closing quote",
		"add_header X a\"b\";":                           "must start a value",
		"if $a { }":                                      "needs a condition",
		"location /a {\n  if ($a = b {\n}\n}":            "closing )",
		"gzip on; x {{{{ y":                              "line 1",
		"a { b { c { d { }}}} ":                          "nested too deeply",
		"proxy_read_timeout 5parsecs;":                   "not a time",
		"client_max_body_size lots;":                     "not a size",
		"gzip maybe;":                                    "on or off",
		"add_header X;":                                  "2 to 3 values",
		"location /a;":                                   "needs a block",
		"gzip on { }":                                    "does not take a block",
		"location / { gzip on; }":                        "main location",
		"location @named { gzip on; }":                   "named locations",
		"location ^ /a { gzip on; }":                     "not a location modifier",
		"location /a { location /b { gzip on; } }":       "not allowed inside a location",
		"location /a { return /new; }":                   "status code",
		"location /a { rewrite ^/x /y maybe; }":          "last, break, redirect or permanent",
		"error_page 404 notapath;":                       "path starting with /",
		"allow somewhere;":                               "not an address",
		"location /a { if ($a ~~ b) { return 403; } }":   "condition is",
		"location /a { if ($a b) { return 403; } }":      "condition is",
		"location /a { if (b = c) { return 403; } }":     "start with a variable",
		"location /a { proxy_set_header Bad_Name x; }":   "not a header name",
		"nonsense 1;":                                    "not supported",
		"gzip_comp_level 12;":                            "level from 1 to 9",
		"add_header X y sometimes;":                      "can only be always",
		"large_client_header_buffers 4;":                 "2 values",
		"location /a { proxy_pass http://example.com; }": "can only be $commypanel_upstream",
	}
	for in, want := range cases {
		_, err := CheckCustom(in)
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: err = %v, want it to mention %q", in, err, want)
		}
	}
}

func TestCustomReportsSeveralProblemsButNotAnEndlessList(t *testing.T) {
	_, err := CheckCustom("root /;\nalias /;\ninclude x;\naccess_log x;\nerror_log x;\nlisten 1;\nserver_name x;\n")
	if err == nil {
		t.Fatal("expected an error")
	}
	msg := err.Error()
	if strings.Count(msg, "line ") != maxCustomErrors || !strings.Contains(msg, "more problems") {
		t.Errorf("message = %s", msg)
	}
}

func TestCustomLimits(t *testing.T) {
	if _, err := CheckCustom(strings.Repeat("# "+strings.Repeat("x", 100)+"\n", 200)); err == nil || !strings.Contains(err.Error(), "too long") {
		t.Errorf("long input: %v", err)
	}
	if _, err := CheckCustom(strings.Repeat("gzip on;\n", maxCustomNodes+1)); err == nil || !strings.Contains(err.Error(), "too many") {
		t.Errorf("many directives: %v", err)
	}
}

func TestCustomIfConditionsWithParenthesesAndBareWords(t *testing.T) {
	got := mustCheck(t, "location /a {\n  if ($mode = fast) { return 204; }\n  if ($uri ~ ^/(a|b)$) { return 204; }\n  if ($ready) { return 204; }\n}\n")
	for _, want := range []string{"if ($mode = fast) {", "if ($uri ~ ^/(a|b)$) {", "if ($ready) {"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
}

// The output of CheckCustom must itself be accepted and come out unchanged,
// whatever went in: that is what keeps the rendered config in step with the
// checked one.
func TestCustomOutputIsStable(t *testing.T) {
	for _, in := range []string{
		"gzip on;",
		"location ~* \\.(jpg|png)$ { expires 30d; }",
		"add_header X-A \"quote \\\" and \\\\ backslash\";",
		"location /a { return 200 \"hello; world\"; }",
		"location /a { if ($a ~* \"x y\") { set $b \"c d\"; return 403; } }",
	} {
		first := mustCheck(t, in)
		if second := mustCheck(t, first); second != first {
			t.Errorf("not stable for %q:\nfirst:\n%s\nsecond:\n%s", in, first, second)
		}
	}
}

func FuzzCheckCustom(f *testing.F) {
	for _, seed := range []string{
		"", "gzip on;", "location /a { return 301 /b; }", "add_header X \"a\\\"b\";",
		"if ($a = b) { }", "location ~ \\.php$ { deny all; }", "# c\nclient_max_body_size 1m;",
		"root /; # x", "x { x { x {", "add_header X 'y",
	} {
		f.Add(seed)
	}
	f.Fuzz(func(t *testing.T, in string) {
		out, err := CheckCustom(in)
		if err != nil {
			return
		}
		again, err := CheckCustom(out)
		if err != nil || again != out {
			t.Fatalf("output of %q is not stable: %v\nfirst:\n%s\nagain:\n%s", in, err, out, again)
		}
		// Nothing outside the allow-list may survive into the output.
		nodes, err := parseCustom(out)
		if err != nil {
			t.Fatal(err)
		}
		var walk func([]node)
		walk = func(ns []node) {
			for _, n := range ns {
				if n.name != "" {
					if _, ok := specs[n.name]; !ok {
						t.Fatalf("directive %q survived for input %q", n.name, in)
					}
					walk(n.block)
				}
			}
		}
		walk(nodes)
	})
}

// --- rendering ---------------------------------------------------------------

func customSite(custom string, cert string) Site {
	return Site{Name: "shop", Domains: []string{"shop.example.com"}, UpstreamPort: 20007, Certificate: cert, Custom: custom}
}

func TestRenderPutsCustomDirectivesInTheServingBlockOnly(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{version: "nginx version: nginx/1.26.0"}, false)

	http, err := m.Render(customSite("client_max_body_size 50m;\nlocation /api/ { proxy_pass $commypanel_upstream; }", ""))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"set $commypanel_upstream http://127.0.0.1:20007;",
		"# Custom directives, edited in CommyPanel.",
		"    client_max_body_size 50m;",
		"        proxy_pass $commypanel_upstream;",
	} {
		if !strings.Contains(http, want) {
			t.Errorf("http config missing %q:\n%s", want, http)
		}
	}
	if strings.Index(http, "client_max_body_size") > strings.Index(http, "location / {") {
		t.Error("custom directives must come before the generated location /")
	}

	https, err := m.Render(customSite("client_max_body_size 50m;", "shop"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Count(https, "client_max_body_size") != 1 {
		t.Errorf("custom directives must appear once, in the HTTPS block:\n%s", https)
	}
	if i, j := strings.Index(https, "client_max_body_size"), strings.Index(https, "listen 443"); i < j {
		t.Error("custom directives landed in the port 80 block")
	}
}

func TestRenderWithoutCustomHasNoCustomSection(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{version: "nginx version: nginx/1.26.0"}, false)
	conf, err := m.Render(customSite("", ""))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(conf, "Custom directives") {
		t.Errorf("unexpected custom section:\n%s", conf)
	}
}

func TestACMELocationBeatsRegexLocationsAndIgnoresDeny(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{version: "nginx version: nginx/1.26.0"}, false)
	conf, err := m.Render(customSite("deny all;\nlocation ~ ^/ { return 403; }", ""))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(conf, "location ^~ /.well-known/acme-challenge/ {\n        allow all;") {
		t.Errorf("the ACME location must be ^~ and allow everyone:\n%s", conf)
	}
}

func TestSiteValidateAndRenderRefuseBadCustomText(t *testing.T) {
	m, _ := newManager(t, &fakeRunner{version: "nginx version: nginx/1.26.0"}, false)
	s := customSite("root /;", "")

	var input *validate.InputError
	if err := s.Validate(); !errors.As(err, &input) {
		t.Errorf("Validate: %v", err)
	}
	if _, err := m.Render(s); err == nil {
		t.Error("Render must refuse it too")
	}
}

func TestApplyWithBadCustomWritesNothingAndNeverTouchesNginx(t *testing.T) {
	run := &fakeRunner{version: "nginx version: nginx/1.26.0"}
	m, dir := newManager(t, run, false)

	if err := m.Apply(context.Background(), customSite("include /etc/passwd;", "")); err == nil {
		t.Fatal("expected a refusal")
	}
	if entries, _ := os.ReadDir(filepath.Join(dir, "sites")); len(entries) != 0 {
		t.Errorf("files were written: %v", entries)
	}
	for _, call := range run.calls {
		if strings.Contains(call, "reload") || strings.HasSuffix(call, "-t") {
			t.Errorf("nginx was asked to %q for a refused site", call)
		}
	}
}
