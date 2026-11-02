package docker

import (
	"context"
	"errors"
	"path/filepath"
	"strings"
	"testing"

	"commypanel/agent/internal/validate"
)

func TestNetworksListsMembersAndSkipsWhatVanished(t *testing.T) {
	m, _, _ := setup(t)

	got, err := m.Networks(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 {
		t.Fatalf("got %d networks, want 1 (the second was removed meanwhile): %+v", len(got), got)
	}
	n := got[0]
	if n.Name != "commypanel" || n.Driver != "bridge" || n.Subnet != "172.20.0.0/16" || n.Gateway != "172.20.0.1" || n.Internal {
		t.Errorf("network = %+v", n)
	}
	if len(n.Containers) != 2 || n.Containers[0] != (NetworkMember{Name: "shop-1", IP: "172.20.0.2"}) || n.Containers[1].Name != "shop-2" {
		t.Errorf("members (sorted, without the /16) = %+v", n.Containers)
	}
}

func TestListReportsNetworksPublishedPortsAndVolumeMounts(t *testing.T) {
	m, d, dir := setup(t)
	d.mounts = []string{filepath.Join(dir, "volumes", "shop", "uploads")}

	got, err := m.List(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	c := got[0]

	if len(c.Networks) != 2 || c.Networks[0] != (ContainerNetwork{Name: "bridge", IP: "172.17.0.2"}) || c.Networks[1].Name != "commypanel" {
		t.Errorf("networks = %+v", c.Networks)
	}
	if len(c.Ports) != 1 || c.Ports[0] != (ContainerPort{Container: 8080, Host: 20001, HostIP: "127.0.0.1", Protocol: "tcp"}) {
		t.Errorf("ports (published only) = %+v", c.Ports)
	}
	if len(c.Mounts) != 1 || c.Mounts[0] != (ContainerMount{Source: "shop/uploads", Destination: "/data"}) {
		t.Errorf("mounts (volumes only, relative) = %+v", c.Mounts)
	}
}

func TestDryRunHasNoNetworks(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, NewEngine("http://127.0.0.1:1", nil))
	got, err := m.Networks(context.Background())
	if err != nil || got == nil || len(got) != 0 {
		t.Errorf("got %v, %v", got, err)
	}
}

func TestOnlyOurOwnNetworksCanBeNamed(t *testing.T) {
	m, d, dir := setup(t)

	for _, bad := range []string{"host", "bridge", "none", "container:abc", "default", "commypanel_x", "commypanelx", "commypanel-", "Commypanel-shop", "commypanel-" + strings.Repeat("a", 60), "other-shop", "../x"} {
		spec := baseSpec()
		spec.Network = bad
		if err := m.Run(context.Background(), spec); err == nil {
			t.Errorf("network %q must be refused", bad)
		}
	}
	for _, request := range d.requests {
		if request == "POST /containers/create" {
			t.Fatal("a container was created for a refused network")
		}
	}

	for _, good := range []string{"commypanel", "commypanel-shop", "commypanel-my-app-2"} {
		spec := baseSpec()
		spec.Network = good
		if err := m.Run(context.Background(), spec); err != nil {
			t.Errorf("network %q is refused: %v", good, err)
		}
	}
	_ = dir
}

func TestAnAppsContainerJoinsItsOwnNetworkAndNothingElse(t *testing.T) {
	m, d, _ := setup(t)
	d.netMissing = true
	spec := baseSpec()
	spec.Network = "commypanel-shop"

	if err := m.Run(context.Background(), spec); err != nil {
		t.Fatal(err)
	}
	if got := d.created["HostConfig"].(map[string]any)["NetworkMode"]; got != "commypanel-shop" {
		t.Errorf("NetworkMode = %v", got)
	}
	if !d.has("POST /networks/create") {
		t.Errorf("the app's network was not created: %v", d.requests)
	}
}

func TestRemoveNetwork(t *testing.T) {
	m, d, _ := setup(t)

	if err := m.RemoveNetwork(context.Background(), "commypanel-shop"); err != nil {
		t.Fatal(err)
	}
	if !d.has("DELETE /networks/commypanel-shop") {
		t.Errorf("requests = %v", d.requests)
	}

	// Gone already: nothing to do.
	d.netMissing = true
	before := len(d.requests)
	if err := m.RemoveNetwork(context.Background(), "commypanel-shop"); err != nil {
		t.Errorf("a missing network: %v", err)
	}
	for _, r := range d.requests[before:] {
		if strings.HasPrefix(r, "DELETE") {
			t.Error("nothing should be deleted when the network is not there")
		}
	}
}

func TestRemoveNetworkRefusesWhatItShould(t *testing.T) {
	m, d, _ := setup(t)

	for _, name := range []string{"commypanel", "host", "bridge", "none", "other", ""} {
		if err := m.RemoveNetwork(context.Background(), name); err == nil {
			t.Errorf("%q must be refused", name)
		}
	}

	d.netForeign = true
	if err := m.RemoveNetwork(context.Background(), "commypanel-shop"); err == nil {
		t.Error("a network the agent did not create must be refused")
	}
	for _, r := range d.requests {
		if strings.HasPrefix(r, "DELETE /networks") {
			t.Errorf("a refused removal still sent %q", r)
		}
	}

	d.netForeign = false
	d.netDeleteStatus = 403
	var ie *validate.InputError
	if err := m.RemoveNetwork(context.Background(), "commypanel-shop"); !errors.As(err, &ie) || !strings.Contains(ie.Msg, "containers attached") {
		t.Errorf("a network in use should say so: %v", err)
	}
}

func TestRemoveNetworkDryRun(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir(), DryRun: true}, NewEngine("http://127.0.0.1:1", nil))
	if err := m.RemoveNetwork(context.Background(), "commypanel-shop"); err != nil {
		t.Errorf("dry-run must not touch Docker: %v", err)
	}
}

func TestConnectAndDisconnectOnlyLinkNetworks(t *testing.T) {
	m, d, _ := setup(t)
	ctx := context.Background()

	if err := m.ConnectNetwork(ctx, "db-main", "commypanel-link-d3", []string{"main"}); err != nil {
		t.Fatal(err)
	}
	if len(d.connected) != 1 || d.connected[0] != "commypanel-link-d3" {
		t.Errorf("connected %v", d.connected)
	}
	if err := m.DisconnectNetwork(ctx, "db-main", "commypanel-link-d3"); err != nil {
		t.Fatal(err)
	}
	if !d.has("POST /networks/commypanel-link-d3/disconnect") {
		t.Errorf("requests = %v", d.requests)
	}

	before := len(d.requests)
	for _, bad := range []string{"host", "bridge", "none", "commypanel", "commypanel-shop", "commypanel-db-main", "other-link-1", ""} {
		if err := m.ConnectNetwork(ctx, "db-main", bad, nil); err == nil {
			t.Errorf("connect %q must be refused", bad)
		}
		if err := m.DisconnectNetwork(ctx, "db-main", bad); err == nil {
			t.Errorf("disconnect %q must be refused", bad)
		}
	}
	if err := m.ConnectNetwork(ctx, "db-main", "commypanel-link-d3", []string{"Bad Alias"}); err == nil {
		t.Error("a bad alias must be refused")
	}
	for _, r := range d.requests[before:] {
		if strings.Contains(r, "/connect") || strings.Contains(r, "/disconnect") {
			t.Errorf("a refused request reached Docker: %s", r)
		}
	}

	d.labels = map[string]string{"other": "x"}
	if err := m.ConnectNetwork(ctx, "db-main", "commypanel-link-d3", nil); err == nil {
		t.Error("a container the agent did not create must be refused")
	}
}

func TestPruneRemovesOnlyEmptyLinkNetworks(t *testing.T) {
	m, d, _ := setup(t)
	removed, err := m.PruneLinkNetworks(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	// The fake daemon lists the shared network with two containers: nothing to prune.
	if len(removed) != 0 {
		t.Errorf("removed %v", removed)
	}
	for _, r := range d.requests {
		if strings.HasPrefix(r, "DELETE /networks") {
			t.Errorf("unexpected %s", r)
		}
	}
}

func TestAContainerCannotJoinAnotherAppsOrADatabaseServersNetwork(t *testing.T) {
	m := NewManager(Config{DataDir: t.TempDir()}, nil)
	base := ContainerSpec{Name: "evil-1", Image: "commypanel/evil:r1", Network: "commypanel-evil"}

	for _, name := range []string{"commypanel-shop", "commypanel-db-main", "commypanel", "bridge", "host"} {
		s := base
		s.Networks = []NetworkAttach{{Name: name, Aliases: []string{"db-main"}}}
		if err := m.Validate(s); err == nil {
			t.Errorf("joining %q as an extra network was accepted", name)
		}
	}

	s := base
	s.Networks = []NetworkAttach{{Name: "commypanel-link-d7", Aliases: []string{"main"}}}
	if err := m.Validate(s); err != nil {
		t.Errorf("a link network must still be allowed: %v", err)
	}

	s = base
	s.Network = "commypanel-link-d7"
	if err := m.Validate(s); err == nil {
		t.Error("a link network was accepted as the container's own network")
	}
}
