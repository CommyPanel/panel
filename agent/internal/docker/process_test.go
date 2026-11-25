package docker

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
)

// processSpec is an app container that is one process of an app: same image as the web container, its
// own command, nothing published.
func processSpec() ContainerSpec {
	return ContainerSpec{
		Name: "shop-3-queue", Image: "commypanel/shop:3",
		Command:     []string{"php", "artisan", "queue:work", "--tries=3"},
		StopTimeout: 90, Restart: "on-failure", Process: "queue",
	}
}

func TestAProcessContainerGetsItsCommandStopTimeoutRestartPolicyAndLabel(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.Run(context.Background(), processSpec()); err != nil {
		t.Fatal(err)
	}

	cmd, _ := d.created["Cmd"].([]any)
	if len(cmd) != 4 || cmd[0] != "php" || cmd[2] != "queue:work" {
		t.Errorf("Cmd = %v", d.created["Cmd"])
	}
	if d.created["StopTimeout"] != float64(90) {
		t.Errorf("StopTimeout = %v", d.created["StopTimeout"])
	}
	host := d.created["HostConfig"].(map[string]any)
	if host["RestartPolicy"].(map[string]any)["Name"] != "on-failure" {
		t.Errorf("restart policy = %v", host["RestartPolicy"])
	}
	labels := d.created["Labels"].(map[string]any)
	if labels[ProcessLabel] != "queue" || labels[ManagedLabel] != "true" {
		t.Errorf("labels = %v", labels)
	}
	// Everything else stays as constrained as for any app container.
	if host["Privileged"] != false || host["NetworkMode"] != "commypanel" {
		t.Errorf("host config = %v", host)
	}
	if opts := host["SecurityOpt"].([]any); len(opts) != 1 || opts[0] != "no-new-privileges:true" {
		t.Errorf("security opts = %v", opts)
	}
}

func TestAProcessPublishesNothingWithHostPortZero(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.Run(context.Background(), processSpec()); err != nil {
		t.Fatal(err)
	}

	host := d.created["HostConfig"].(map[string]any)
	if _, ok := host["PortBindings"]; ok {
		t.Errorf("nothing may be published: %v", host["PortBindings"])
	}
	if _, ok := d.created["ExposedPorts"]; ok {
		t.Errorf("nothing may be exposed: %v", d.created["ExposedPorts"])
	}

	// A port without a host port is still an error: there is no way to publish on another address.
	spec := processSpec()
	spec.ContainerPort = 8080
	if err := m.Run(context.Background(), spec); !isInputError(err) {
		t.Errorf("container_port without host_port: %v", err)
	}
}

func TestWithoutProcessFieldsAContainerIsAsBefore(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.Run(context.Background(), baseSpec()); err != nil {
		t.Fatal(err)
	}

	for _, key := range []string{"Cmd", "StopTimeout"} {
		if _, ok := d.created[key]; ok {
			t.Errorf("%s must not be set: %v", key, d.created[key])
		}
	}
	if _, ok := d.created["Labels"].(map[string]any)[ProcessLabel]; ok {
		t.Error("no process label without a process")
	}
	if d.created["HostConfig"].(map[string]any)["RestartPolicy"].(map[string]any)["Name"] != "unless-stopped" {
		t.Error("the default restart policy changed")
	}
}

func TestProcessFieldsAreChecked(t *testing.T) {
	m, d, _ := setup(t)
	long := strings.Repeat("a", maxCommandArgBytes+1)
	many := make([]string, maxCommandArgs+1)
	for i := range many {
		many[i] = "x"
	}

	bad := map[string]func(*ContainerSpec){
		"empty argument":     func(s *ContainerSpec) { s.Command = []string{"php", ""} },
		"nul in an argument": func(s *ContainerSpec) { s.Command = []string{"php", "a\x00b"} },
		"argument too long":  func(s *ContainerSpec) { s.Command = []string{"php", long} },
		"too many arguments": func(s *ContainerSpec) { s.Command = many },
		"all together too long": func(s *ContainerSpec) {
			s.Command = []string{strings.Repeat("a", 4000), strings.Repeat("b", 4000), strings.Repeat("c", 4000), strings.Repeat("d", 4000), strings.Repeat("e", 4000)}
		},
		"command on a db image": func(s *ContainerSpec) {
			s.Image = "postgres:17"
			s.Name = "db-x"
			s.Process = ""
			s.StopTimeout = 0
			s.Restart = ""
		},
		"command on a foreign one": func(s *ContainerSpec) {
			s.Image = "library/alpine:3"
			s.Process = ""
			s.StopTimeout = 0
			s.Restart = ""
		},
		"negative stop timeout":   func(s *ContainerSpec) { s.StopTimeout = -1 },
		"stop timeout too big":    func(s *ContainerSpec) { s.StopTimeout = maxStopTimeout + 1 },
		"unknown restart policy":  func(s *ContainerSpec) { s.Restart = "always" },
		"restart with a shell":    func(s *ContainerSpec) { s.Restart = "no; rm -rf /" },
		"process uppercase":       func(s *ContainerSpec) { s.Process = "Queue" },
		"process starts with 1":   func(s *ContainerSpec) { s.Process = "1queue" },
		"process with a slash":    func(s *ContainerSpec) { s.Process = "queue/x" },
		"process too long":        func(s *ContainerSpec) { s.Process = "q" + strings.Repeat("a", 31) },
		"process label injection": func(s *ContainerSpec) { s.Process = "queue\ncom.commypanel.managed=false" },
	}
	for name, mutate := range bad {
		spec := processSpec()
		mutate(&spec)
		if err := m.Run(context.Background(), spec); !isInputError(err) {
			t.Errorf("%s: want an input error, got %v", name, err)
		}
	}
	if d.has("POST /containers/create") {
		t.Error("nothing invalid may reach Docker")
	}
}

func TestProcessFieldsAtTheirLimitsAreAccepted(t *testing.T) {
	m, _, _ := setup(t)

	for name, mutate := range map[string]func(*ContainerSpec){
		"stop timeout 0":       func(s *ContainerSpec) { s.StopTimeout = 0 },
		"stop timeout 600":     func(s *ContainerSpec) { s.StopTimeout = maxStopTimeout },
		"argument of 4096":     func(s *ContainerSpec) { s.Command = []string{"echo", strings.Repeat("a", maxCommandArgBytes)} },
		"64 arguments":         func(s *ContainerSpec) { s.Command = make([]string, maxCommandArgs); fill(s.Command) },
		"restart no":           func(s *ContainerSpec) { s.Restart = "no" },
		"restart unless":       func(s *ContainerSpec) { s.Restart = "unless-stopped" },
		"31 character name":    func(s *ContainerSpec) { s.Process = "q" + strings.Repeat("a", 30) },
		"no command at all":    func(s *ContainerSpec) { s.Command = nil },
		"a process with a vol": func(s *ContainerSpec) {},
	} {
		spec := processSpec()
		mutate(&spec)
		if err := m.Validate(spec); err != nil {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func fill(a []string) {
	for i := range a {
		a[i] = "x"
	}
}

func TestAProcessMountsTheSameVolumesAsAnyAppContainer(t *testing.T) {
	m, d, dir := setup(t)
	spec := processSpec()
	spec.Volumes = []Volume{{Source: filepath.Join(dir, "volumes", "shop", "storage"), Target: "/app/storage"}}

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}

	binds := d.created["HostConfig"].(map[string]any)["Binds"].([]any)
	if len(binds) != 1 || !strings.HasSuffix(binds[0].(string), ":/app/storage") {
		t.Errorf("binds = %v", binds)
	}

	spec.Volumes = []Volume{{Source: "/var/run/docker.sock", Target: "/s"}}
	if err := m.Validate(spec); !isInputError(err) {
		t.Errorf("a mount outside the volumes folder must be refused: %v", err)
	}
}

func TestStopAndRestartLetTheContainerUseItsOwnStopTimeout(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()

	if err := m.Stop(ctx, "demo-1"); err != nil {
		t.Fatal(err)
	}
	if err := m.Restart(ctx, "demo-1"); err != nil {
		t.Fatal(err)
	}

	for _, req := range []string{"POST /containers/demo-1/stop", "POST /containers/demo-1/restart"} {
		if !d.has(req) {
			t.Fatalf("%s did not happen: %v", req, d.requests)
		}
		if q := d.queries[req]; q != "" {
			t.Errorf("%s must not pass its own timeout (it would override StopTimeout): ?%s", req, q)
		}
	}
}

func TestTheListSaysWhichProcessEachContainerRuns(t *testing.T) {
	m, _, _ := setup(t)

	list, err := m.List(context.Background())
	if err != nil || len(list) != 1 || list[0].Process != "queue" {
		t.Errorf("list = %+v, %v", list, err)
	}
}

func TestATaskTakesAWholeScript(t *testing.T) {
	m, d, _ := setup(t)
	line := "php artisan migrate --force\n"
	script := strings.Repeat(line, (16<<10)/len(line)+1)[:16<<10] // exactly 16 KiB

	spec := taskSpec()
	spec.Command = []string{"sh", "-ec", script}
	if _, err := m.RunTask(context.Background(), spec, func(string) {}); err != nil {
		t.Fatalf("a 16 KiB script must be accepted: %v", err)
	}
	cmd, _ := d.created["Cmd"].([]any)
	if len(cmd) != 3 || len(cmd[2].(string)) != 16<<10 {
		t.Errorf("the script was changed on its way: %d args", len(cmd))
	}

	spec.Command = []string{"sh", "-ec", script + "x"}
	if _, err := m.RunTask(context.Background(), spec, func(string) {}); !isInputError(err) {
		t.Errorf("a script longer than 16 KiB must be refused: %v", err)
	}
}

// FuzzCheckCommand: whatever the argument list, the checker must agree with a plain restatement of the rules.
func FuzzCheckCommand(f *testing.F) {
	f.Add("php", "artisan", "queue:work")
	f.Add("", "x", "y")
	f.Add("a\x00b", "x", "y")
	f.Add(strings.Repeat("a", 5000), "x", "y")
	f.Fuzz(func(t *testing.T, a, b, c string) {
		cmd := []string{a, b, c}
		want := true
		total := 0
		for _, arg := range cmd {
			if arg == "" || len(arg) > maxCommandArgBytes || strings.ContainsRune(arg, 0) {
				want = false
			}
			total += len(arg)
		}
		if total > maxTaskCmdBytes {
			want = false
		}
		if got := checkCommand(cmd, maxCommandArgBytes) == nil; got != want {
			t.Errorf("checkCommand(%q) = %v, want %v", cmd, got, want)
		}
	})
}

func TestDryRunChecksAProcessToo(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, nil)

	if err := m.Run(context.Background(), processSpec()); err != nil {
		t.Errorf("a good process: %v", err)
	}
	spec := processSpec()
	spec.Command = []string{"php", ""}
	if err := m.Run(context.Background(), spec); !isInputError(err) {
		t.Errorf("a bad command must be refused in dry-run too: %v", err)
	}
}

func TestTheAdminSQLGivesTheUserTheServer(t *testing.T) {
	if got := adminSQL("mariadb", "app_admin"); got != "GRANT ALL PRIVILEGES ON *.* TO 'app_admin'@'%' WITH GRANT OPTION;\nFLUSH PRIVILEGES;\n" {
		t.Errorf("mariadb admin: %q", got)
	}
	if adminSQL("mysql", "u") != adminSQL("mariadb", "u") {
		t.Error("mysql and mariadb use the same statement")
	}
	if got := adminSQL("postgres", "app_admin"); got != "ALTER ROLE \"app_admin\" CREATEDB CREATEROLE;\n" {
		t.Errorf("postgres admin: %q", got)
	}
}

func TestAnAdminDatabaseRunsTheCreateSQLAndThenTheAdminSQLThroughStdin(t *testing.T) {
	for engine, want := range map[string]string{
		"mariadb":  "GRANT ALL PRIVILEGES ON *.* TO 'app'@'%' WITH GRANT OPTION",
		"postgres": `ALTER ROLE "app" CREATEDB CREATEROLE`,
	} {
		m, d, _ := setup(t)

		if err := m.CreateAdminDatabase(context.Background(), target(engine), "app", "app", userPw); err != nil {
			t.Fatalf("%s: %v", engine, err)
		}

		d.mu.Lock()
		stdin := d.stdin
		cmd, _ := d.execBody["Cmd"].([]any)
		d.mu.Unlock()
		if !strings.Contains(stdin, want) {
			t.Errorf("%s: stdin = %q", engine, stdin)
		}
		if !strings.Contains(stdin, createSQL(engine, "app", "app", userPw)) {
			t.Errorf("%s: the ordinary create statements must come first: %q", engine, stdin)
		}
		for _, a := range cmd {
			if s, _ := a.(string); strings.Contains(s, userPw) || strings.Contains(s, rootPw) {
				t.Errorf("%s: a secret is on the command line: %v", engine, cmd)
			}
		}
	}
}

func TestAnOrdinaryDatabaseNeverGetsTheAdminRights(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.CreateDatabase(context.Background(), target("mariadb"), "shop", "shop_user", userPw); err != nil {
		t.Fatal(err)
	}

	d.mu.Lock()
	defer d.mu.Unlock()
	if strings.Contains(d.stdin, "*.*") || strings.Contains(d.stdin, "GRANT OPTION") {
		t.Errorf("an ordinary user got server rights: %q", d.stdin)
	}
}

func TestAnAdminDatabaseIsCheckedLikeAnyOther(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()

	for _, b := range []struct{ db, user, pw string }{
		{"sh op", "u", userPw}, {"shop", "u;DROP", userPw}, {"shop", "u", "short"}, {"shop", "root", userPw}, {"shop", "postgres", userPw}, {"mysql", "u", userPw},
	} {
		if err := m.CreateAdminDatabase(ctx, target("mariadb"), b.db, b.user, b.pw); err == nil {
			t.Errorf("%+v must be refused", b)
		}
	}
	for _, r := range d.requests {
		if strings.Contains(r, "/exec") {
			t.Errorf("an exec was created for a refused name: %v", d.requests)
		}
	}

	d.labels = map[string]string{"other": "x"}
	if err := m.CreateAdminDatabase(ctx, target("mariadb"), "shop", "u", userPw); err == nil {
		t.Error("a container the agent did not create must be refused")
	}
}

func TestDroppingAPostgresUserThatOwnsMoreSaysSo(t *testing.T) {
	m, d, _ := setup(t)
	d.execExit = 1
	d.execReply = `ERROR:  role "app" cannot be dropped because some objects depend on it` + "\nDETAIL:  owner of database tenant_1\n"

	err := m.DropDatabase(context.Background(), target("postgres"), "app", "app")

	if !isInputError(err) || !strings.Contains(err.Error(), "still owns other databases") {
		t.Errorf("err = %v", err)
	}
	if strings.Contains(err.Error(), "tenant_1") {
		t.Errorf("the message should be the agent's own sentence: %v", err)
	}
}

func TestDryRunAdminDatabaseTouchesNothing(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, nil)

	if err := m.CreateAdminDatabase(context.Background(), target("postgres"), "app", "app", userPw); err != nil {
		t.Errorf("dry-run: %v", err)
	}
}
