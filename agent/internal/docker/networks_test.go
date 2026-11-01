package docker

import (
	"context"
	"path/filepath"
	"testing"
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
