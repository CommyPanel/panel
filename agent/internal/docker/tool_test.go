package docker

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

const toolBase = "https://commy.example.com:2053/s/abcdefghijklmnopqrstuvwx/"

func toolSpec(engine string) ToolSpec {
	tool := "phpmyadmin"
	if engine == "postgres" {
		tool = "adminer"
	}
	return ToolSpec{Name: "dbtool-0123456789ab", Tool: tool, Engine: engine, Server: "main", User: "shop", Password: userPw, Port: 29001, BaseURL: toolBase}
}

func TestAToolRunsOnItsDatabaseServersNetworkAloneAndWithoutPrivileges(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.RunTool(context.Background(), toolSpec("mariadb")); err != nil {
		t.Fatalf("run: %v", err)
	}

	raw, _ := json.Marshal(d.created)
	body := string(raw)
	for _, want := range []string{
		`"Image":"phpmyadmin:5.2"`,
		`"NetworkMode":"commypanel-db-main"`,
		`"Privileged":false`,
		`"no-new-privileges:true"`,
		`"RestartPolicy":{"Name":"no"}`,
		`"HostIp":"127.0.0.1"`,
		`"HostPort":"29001"`,
		`"com.commypanel.dbtool":"true"`,
		`"com.commypanel.managed":"true"`,
		`PMA_HOST=db-main`,
		`PMA_ABSOLUTE_URI=` + toolBase,
	} {
		if !strings.Contains(body, want) {
			t.Errorf("the container does not have %s\n%s", want, body)
		}
	}
	if strings.Contains(body, userPw) || strings.Contains(body, "PMA_PASSWORD") || strings.Contains(body, "PMA_USER") {
		t.Errorf("the password, or a login, is in the container's environment: %s", body)
	}
	if strings.Contains(body, `"Binds":["`) {
		t.Errorf("a tool must not mount anything: %s", body)
	}
}

func TestAdminerIsTheToolForPostgres(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.RunTool(context.Background(), toolSpec("postgres")); err != nil {
		t.Fatalf("run: %v", err)
	}
	raw, _ := json.Marshal(d.created)
	body := string(raw)
	if !strings.Contains(body, `"Image":"adminer:5"`) || !strings.Contains(body, `ADMINER_DEFAULT_SERVER=db-main`) || strings.Contains(body, "PMA_") {
		t.Errorf("body = %s", body)
	}
	if strings.Contains(body, userPw) {
		t.Errorf("the password must not be in the environment: %s", body)
	}
}

func TestAToolIsRefusedForAnythingButItsOwnToolAndNetwork(t *testing.T) {
	m, d, _ := setup(t)
	bad := map[string]func(*ToolSpec){
		"phpMyAdmin for postgres": func(s *ToolSpec) { s.Engine = "postgres" },
		"adminer for mariadb":     func(s *ToolSpec) { s.Tool = "adminer" },
		"an unknown tool":         func(s *ToolSpec) { s.Tool = "pgadmin" },
		"not a dbtool name":       func(s *ToolSpec) { s.Name = "shop-1" },
		"another network":         func(s *ToolSpec) { s.Server = "../bridge" },
		"a root user":             func(s *ToolSpec) { s.User = "root" },
		"a short password":        func(s *ToolSpec) { s.Password = "short" },
		"a privileged port":       func(s *ToolSpec) { s.Port = 80 },
		"an http address":         func(s *ToolSpec) { s.BaseURL = "http://commy.example.com:2053/s/abcdefghijklmnopqrstuvwx/" },
		"an address with a path":  func(s *ToolSpec) { s.BaseURL = "https://commy.example.com:2053/evil/s/abcdefghijklmnopqrstuvwx/" },
		"an address with quotes":  func(s *ToolSpec) { s.BaseURL = `https://a"b/s/abcdefghijklmnopqrstuvwx/` },
	}
	for name, mutate := range bad {
		s := toolSpec("mariadb")
		mutate(&s)
		d.created = nil
		if err := m.RunTool(context.Background(), s); err == nil {
			t.Errorf("%s was accepted", name)
		}
		if d.created != nil {
			t.Errorf("%s: a container was created", name)
		}
	}
}

func TestToolImagesAreNotAcceptedByTheGenericContainerActions(t *testing.T) {
	m, _, _ := setup(t)
	for _, image := range []string{"phpmyadmin:5.2", "adminer:5"} {
		spec := ContainerSpec{Name: "x-1", Image: image, Network: "commypanel-db-main"}
		if err := m.Validate(spec); err == nil {
			t.Errorf("%s can be started from a request", image)
		}
		if err := m.PullImage(context.Background(), image); err == nil {
			t.Errorf("%s can be pulled from a request", image)
		}
	}
	if err := m.PullToolImage(context.Background(), "postgres:17"); err == nil {
		t.Error("a database image was accepted as a tool image")
	}
}
