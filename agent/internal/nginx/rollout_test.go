package nginx

import (
	"regexp"
	"strings"
	"testing"
)

func TestBucketRegexMatchesExactlyTheBucketsBelowThePercentage(t *testing.T) {
	for p := 1; p <= 99; p++ {
		re := regexp.MustCompile("^" + bucketRegex(p) + "$")
		for b := 0; b < 100; b++ {
			bucket := string([]byte{byte('0' + b/10), byte('0' + b%10)})
			if got, want := re.MatchString(bucket), b < p; got != want {
				t.Fatalf("percent %d bucket %s: matched %v, want %v (regex %s)", p, bucket, got, want, bucketRegex(p))
			}
		}
	}
}

func rolloutSite() Site {
	return Site{Name: "shop", Domains: []string{"shop.example.com"}, UpstreamPort: 20001, Certificate: "shop", Rollout: &Rollout{Port: 20002, Percent: 30}}
}

func TestARolloutSplitsVisitorsByABucketKeptInACookie(t *testing.T) {
	m := NewManager(Config{SitesDir: t.TempDir(), StateDir: t.TempDir(), DryRun: true, MetricsSocket: "/run/commypanel-metrics/metrics.sock"}, nil)
	conf, err := m.Render(rolloutSite())
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"split_clients \"${request_id}a\" $cp_shop_tens",
		"split_clients \"${request_id}b\" $cp_shop_ones",
		`"~^([0-2][0-9])$" 127.0.0.1:20002;`,
		"default 127.0.0.1:20001;",
		"cp_rollout=$cp_shop_bucket; Path=/; Max-Age=86400; HttpOnly; SameSite=Lax; Secure",
		"set $commypanel_upstream http://$cp_shop_target;",
		"add_header Set-Cookie $cp_shop_cookie always;",
		"proxy_pass $commypanel_upstream;",
		"access_log syslog:server=unix:/run/commypanel-metrics/metrics.sock,nohostname cp_shop;",
		"log_format cp_shop 'shop|$status|$request_time|$upstream_addr|$upstream_status|$upstream_response_time';",
	} {
		if !strings.Contains(conf, want) {
			t.Errorf("missing %q in:\n%s", want, conf)
		}
	}
	if strings.Count(conf, "10% ") != 18 {
		t.Errorf("the bucket digits are not spread evenly:\n%s", conf)
	}
}

func TestAnHTTPOnlySiteGetsACookieWithoutSecure(t *testing.T) {
	m := NewManager(Config{SitesDir: t.TempDir(), StateDir: t.TempDir(), DryRun: true}, nil)
	s := rolloutSite()
	s.Certificate = ""
	conf, _ := m.Render(s)
	if strings.Contains(conf, "; Secure") || !strings.Contains(conf, "HttpOnly; SameSite=Lax\";") {
		t.Errorf("cookie flags:\n%s", conf)
	}
	if strings.Contains(conf, "access_log") || strings.Contains(conf, "log_format") {
		t.Errorf("no statistics were asked for:\n%s", conf)
	}
}

func TestASiteWithoutARolloutProxiesStraightToItsContainer(t *testing.T) {
	m := NewManager(Config{SitesDir: t.TempDir(), StateDir: t.TempDir(), DryRun: true, MetricsSocket: "/run/x.sock"}, nil)
	s := rolloutSite()
	s.Rollout = nil
	conf, _ := m.Render(s)
	if strings.Contains(conf, "split_clients") || strings.Contains(conf, "cp_rollout") || !strings.Contains(conf, "proxy_pass http://127.0.0.1:20001;") {
		t.Errorf("a plain site was changed:\n%s", conf)
	}
	if !strings.Contains(conf, "access_log syslog:server=unix:/run/x.sock,nohostname cp_shop;") {
		t.Errorf("a plain site is not logged:\n%s", conf)
	}
}

func TestHyphensInASiteNameBecomeUnderscoresInVariables(t *testing.T) {
	m := NewManager(Config{SitesDir: t.TempDir(), StateDir: t.TempDir(), DryRun: true, MetricsSocket: "/run/x.sock"}, nil)
	s := rolloutSite()
	s.Name = "my-shop-app"
	conf, _ := m.Render(s)
	if strings.Contains(conf, "$cp_my-") || !strings.Contains(conf, "$cp_my_shop_app_target") || !strings.Contains(conf, "log_format cp_my_shop_app 'my-shop-app|") {
		t.Errorf("names:\n%s", conf)
	}
}

func TestARolloutIsRefusedWhenItCannotWork(t *testing.T) {
	bad := map[string]func(*Site){
		"the same port": func(s *Site) { s.Rollout.Port = s.UpstreamPort },
		"a low port":    func(s *Site) { s.Rollout.Port = 80 },
		"zero percent":  func(s *Site) { s.Rollout.Percent = 0 },
		"a hundred":     func(s *Site) { s.Rollout.Percent = 100 },
		"negative":      func(s *Site) { s.Rollout.Percent = -5 },
	}
	for name, mutate := range bad {
		s := rolloutSite()
		mutate(&s)
		if err := s.Validate(); err == nil {
			t.Errorf("%s was accepted", name)
		}
	}
	if err := rolloutSite().Validate(); err != nil {
		t.Errorf("a good rollout was refused: %v", err)
	}
}
