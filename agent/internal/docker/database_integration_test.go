//go:build integration

package docker

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// Run with: go test -tags integration ./internal/docker -run RealDatabases -v -timeout 20m
// Needs a local Docker daemon and downloads the official database images.
// Each engine is started for real, and the SQL the agent sends is checked by
// connecting as the new user with the password it was given.
func TestRealDatabases(t *testing.T) {
	sock := os.Getenv("DOCKER_SOCK")
	if sock == "" {
		sock = "/var/run/docker.sock"
	}

	for _, c := range []struct {
		engine, image, mount string
	}{
		{"mariadb", "mariadb:11.4", "/var/lib/mysql"},
		{"mysql", "mysql:8.4", "/var/lib/mysql"},
		{"postgres", "postgres:17", "/var/lib/postgresql/data"},
	} {
		t.Run(c.engine, func(t *testing.T) {
			// A short path: the data folder is shared with the Docker VM.
			dataDir, err := os.MkdirTemp("/tmp", "cpdb")
			if err != nil {
				t.Fatal(err)
			}
			m := NewManager(Config{DataDir: dataDir}, NewUnixEngine(sock))
			ctx := context.Background()

			id := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000)
			container := "db-it-" + id
			network := "commypanel-db-it-" + id
			link := "commypanel-link-it-" + id
			t.Cleanup(func() {
				_ = m.Remove(ctx, container)
				exec.Command("docker", "network", "rm", network, link).Run()
				// The image's own user owns the files, so remove them as root in a container.
				exec.Command("docker", "run", "--rm", "-v", dataDir+":/d", "alpine:3", "rm", "-rf", "/d/databases").Run()
				os.RemoveAll(dataDir)
			})

			if err := m.PullImage(ctx, c.image); err != nil {
				t.Fatalf("pull: %v", err)
			}

			env := map[string]string{}
			switch c.engine {
			case "postgres":
				env["POSTGRES_PASSWORD"] = rootPw
			case "mysql":
				env["MYSQL_ROOT_PASSWORD"] = rootPw
			default:
				env["MARIADB_ROOT_PASSWORD"] = rootPw
			}
			spec := ContainerSpec{
				Name: container, Image: c.image, Network: network, Aliases: []string{"main"}, Env: env,
				MemoryMB: 1024,
				Volumes:  []Volume{{Source: filepath.Join(dataDir, "databases", "main"), Target: c.mount}},
				Networks: []NetworkAttach{{Name: link, Aliases: []string{"main"}}},
			}
			if err := m.Run(ctx, spec); err != nil {
				t.Fatalf("run: %v", err)
			}

			target := DatabaseTarget{Container: container, Engine: c.engine, RootPassword: rootPw}
			deadline := time.Now().Add(150 * time.Second)
			for {
				ok, err := m.DatabaseReady(ctx, target)
				if err != nil {
					t.Fatalf("ready: %v", err)
				}
				if ok {
					break
				}
				if time.Now().After(deadline) {
					out, _ := exec.Command("docker", "logs", "--tail", "30", container).CombinedOutput()
					t.Fatalf("never became ready:\n%s", out)
				}
				time.Sleep(3 * time.Second)
			}

			// Both networks are there, with the same name on each.
			info, err := m.List(ctx)
			if err != nil {
				t.Fatal(err)
			}
			var names []string
			for _, ci := range info {
				if ci.Name == container {
					for _, n := range ci.Networks {
						names = append(names, n.Name)
					}
					if len(ci.Ports) != 0 {
						t.Errorf("a database server published ports: %+v", ci.Ports)
					}
				}
			}
			if strings.Join(names, ",") != link+","+network && strings.Join(names, ",") != network+","+link {
				t.Errorf("networks = %v", names)
			}

			// connect proves the user and password really work, over TCP, as an application would.
			connect := func(user, password, db string) (bool, string) {
				var cmd, cenv []string
				if c.engine == "postgres" {
					// By its network name, as an app would: the official image trusts 127.0.0.1, so a
					// loopback connection would accept any password.
					cmd = []string{"psql", "-h", "main", "-U", user, "-d", db, "-tAc", "SELECT current_user"}
					cenv = []string{"PGPASSWORD=" + password}
				} else {
					bin := "mariadb"
					if c.engine == "mysql" {
						bin = "mysql"
					}
					cmd = []string{bin, "-h127.0.0.1", "-u" + user, db, "--batch", "--skip-column-names", "-e", "SELECT current_user()"}
					cenv = []string{"MYSQL_PWD=" + password}
				}
				res, err := m.execIn(ctx, container, cmd, cenv, "")
				if err != nil {
					t.Fatalf("connect exec: %v", err)
				}
				return res.ExitCode == 0, strings.TrimSpace(res.Output)
			}

			if err := m.CreateDatabase(ctx, target, "shop", "shop_user", userPw); err != nil {
				t.Fatalf("create: %v", err)
			}
			if ok, out := connect("shop_user", userPw, "shop"); !ok {
				t.Fatalf("the new user cannot connect: %s", out)
			}
			if ok, _ := connect("shop_user", "WrongPassword1234567", "shop"); ok {
				t.Error("a wrong password was accepted")
			}

			// Running it again is harmless.
			if err := m.CreateDatabase(ctx, target, "shop", "shop_user", userPw); err != nil {
				t.Errorf("creating twice: %v", err)
			}

			// A second database cannot be opened by the first one's user.
			if err := m.CreateDatabase(ctx, target, "blog", "blog_user", "BlogPassword12345678"); err != nil {
				t.Fatalf("second database: %v", err)
			}
			if ok, _ := connect("shop_user", userPw, "blog"); ok {
				t.Error("one database's user can open another database")
			}

			// A dump holds the data, and can be loaded into another database.
			createTable := "CREATE TABLE notes (id int, body text); INSERT INTO notes VALUES (1, 'kept by the dump');\n"
			if err := m.runSQL(ctx, target, "\\c shop\n"+createTable, userPw); err != nil && c.engine == "postgres" {
				t.Fatalf("create table: %v", err)
			}
			if c.engine != "postgres" {
				if err := m.runSQL(ctx, target, "USE shop;\n"+createTable); err != nil {
					t.Fatalf("create table: %v", err)
				}
			}
			dumpPath, err := m.DumpDatabase(ctx, target, "shop")
			if err != nil {
				t.Fatalf("dump: %v", err)
			}
			defer os.Remove(dumpPath)
			dumpText, _ := os.ReadFile(dumpPath)
			if !strings.Contains(string(dumpText), "kept by the dump") {
				t.Errorf("the dump does not hold the row:\n%.400s", dumpText)
			}
			if strings.Contains(string(dumpText), userPw) || strings.Contains(string(dumpText), rootPw) {
				t.Error("the dump holds a password")
			}
			if err := m.CreateDatabase(ctx, target, "copy", "copy_user", "CopyPassword1234567890"); err != nil {
				t.Fatalf("create copy: %v", err)
			}
			load := string(dumpText)
			if c.engine == "postgres" {
				load = "\\c copy\n" + load
			} else {
				load = "USE copy;\n" + load
			}
			cmd, cenv := adminCommand(target)
			if res, err := m.execIn(ctx, container, cmd, cenv, load); err != nil || res.ExitCode != 0 {
				t.Fatalf("loading the dump: %v %+v", err, res)
			}
			readBack := func(db string) string {
				var q, qenv []string
				if c.engine == "postgres" {
					q, qenv = []string{"psql", "-U", "postgres", "-d", db, "-tAc", "SELECT body FROM notes"}, nil
				} else {
					bin := "mariadb"
					if c.engine == "mysql" {
						bin = "mysql"
					}
					q, qenv = []string{bin, "-uroot", db, "--batch", "--skip-column-names", "-e", "SELECT body FROM notes"}, []string{"MYSQL_PWD=" + rootPw}
				}
				res, err := m.execIn(ctx, container, q, qenv, "")
				if err != nil {
					t.Fatal(err)
				}
				return strings.TrimSpace(res.Output)
			}
			if got := readBack("copy"); got != "kept by the dump" {
				t.Errorf("the restored copy holds %q", got)
			}

			// The restore the panel offers: a dump the agent registered goes into a new
			// database, as that database's own user, and an unregistered file does not.
			var gz bytes.Buffer
			zw := gzip.NewWriter(&gz)
			zw.Write(dumpText)
			zw.Close()
			sum := sha256.Sum256(gz.Bytes())
			if err := os.MkdirAll(filepath.Join(dataDir, "backups", "databases", "main"), 0o700); err != nil {
				t.Fatal(err)
			}
			const dumpFile = "shop-20261103-040000.sql.gz"
			if err := os.WriteFile(filepath.Join(dataDir, "backups", "databases", "main", dumpFile), gz.Bytes(), 0o600); err != nil {
				t.Fatal(err)
			}
			if err := m.CreateDatabase(ctx, target, "again", "again_user", "AgainPassword1234567890"); err != nil {
				t.Fatalf("create again: %v", err)
			}
			if err := m.RestoreDatabase(ctx, target, "again", "again_user", "main", dumpFile); err == nil {
				t.Fatal("an unregistered dump was restored")
			}
			if err := m.RegisterDump(hex.EncodeToString(sum[:])); err != nil {
				t.Fatal(err)
			}
			if err := m.RestoreDatabase(ctx, target, "again", "again_user", "main", dumpFile); err != nil {
				t.Fatalf("restore: %v", err)
			}
			if got := readBack("again"); got != "kept by the dump" {
				t.Errorf("the restored database holds %q", got)
			}
			// What was restored belongs to the database's own user, who can read it.
			if ok, out := connect("again_user", "AgainPassword1234567890", "again"); !ok {
				t.Errorf("the database's user cannot connect: %s", out)
			}

			// Rotating the password.
			const next = "NewPassword1234567890"
			if err := m.SetDatabasePassword(ctx, target, "shop_user", next); err != nil {
				t.Fatalf("password: %v", err)
			}
			if ok, _ := connect("shop_user", userPw, "shop"); ok {
				t.Error("the old password still works")
			}
			if ok, out := connect("shop_user", next, "shop"); !ok {
				t.Errorf("the new password does not work: %s", out)
			}

			// Dropping removes the database and the user.
			if err := m.DropDatabase(ctx, target, "shop", "shop_user"); err != nil {
				t.Fatalf("drop: %v", err)
			}
			if ok, _ := connect("shop_user", next, "shop"); ok {
				t.Error("the user can still connect after the drop")
			}
			if err := m.DropDatabase(ctx, target, "shop", "shop_user"); err != nil {
				t.Errorf("dropping twice: %v", err)
			}

			// The data is refused while the server runs, and goes once it is removed.
			if err := m.RemoveDatabaseData(ctx, "main"); err == nil {
				t.Error("the data of a running server was removed")
			}
			if err := m.Remove(ctx, container); err != nil {
				t.Fatal(err)
			}
			// Files belong to the database's own user inside the VM; as root the agent can remove them.
			if err := m.RemoveDatabaseData(ctx, "main"); err != nil {
				t.Logf("removing the data from the host failed (a Docker Desktop style VM may own the files): %v", err)
			}
		})
	}
}
