package system

import (
	"net"
	"net/netip"
	"reflect"
	"testing"
)

func cidr(t *testing.T, s string) net.Addr {
	t.Helper()
	ip, n, err := net.ParseCIDR(s)
	if err != nil {
		t.Fatal(err)
	}
	return &net.IPNet{IP: ip, Mask: n.Mask}
}

func TestOnlyAddressesTheInternetCanReachAreKept(t *testing.T) {
	var addrs []net.Addr
	for _, s := range []string{
		"127.0.0.1/8", "::1/128",
		"10.0.0.2/24", "172.17.0.1/16", "192.168.1.5/24", // private, docker bridge
		"100.64.1.1/10", "169.254.3.4/16", "fe80::1/64", "fd12:3456::1/64",
		"203.0.113.9/24", "2001:db8::5/64", // documentation ranges
		"198.18.0.1/15",
		"65.21.10.20/32", "2a01:4f9:c015:c087::1/64", "65.21.10.20/32", // public (once)
	} {
		addrs = append(addrs, cidr(t, s))
	}

	got := PublicAddresses(addrs, netip.Addr{}, netip.Addr{})

	if !reflect.DeepEqual(got.IPv4, []string{"65.21.10.20"}) || !reflect.DeepEqual(got.IPv6, []string{"2a01:4f9:c015:c087::1"}) {
		t.Errorf("got %+v", got)
	}
	if got.PrimaryIPv4 != "" || got.PrimaryIPv6 != "" {
		t.Errorf("no route source was given: %+v", got)
	}
}

func TestThePrimaryAddressIsOneOfThePublicOnesOrNothing(t *testing.T) {
	addrs := []net.Addr{cidr(t, "65.21.10.20/32"), cidr(t, "65.21.10.21/32"), cidr(t, "10.0.0.2/24")}

	got := PublicAddresses(addrs, netip.MustParseAddr("65.21.10.21"), netip.Addr{})
	if got.PrimaryIPv4 != "65.21.10.21" || !reflect.DeepEqual(got.IPv4, []string{"65.21.10.20", "65.21.10.21"}) {
		t.Errorf("got %+v", got)
	}

	// Behind a NAT the route source is the private address: no primary.
	got = PublicAddresses(addrs, netip.MustParseAddr("10.0.0.2"), netip.Addr{})
	if got.PrimaryIPv4 != "" {
		t.Errorf("a private route source became primary: %+v", got)
	}
}

func TestAMachineWithoutPublicAddressesGivesEmptyListsNotNull(t *testing.T) {
	got := PublicAddresses([]net.Addr{cidr(t, "10.0.0.2/24")}, netip.Addr{}, netip.Addr{})
	if got.IPv4 == nil || got.IPv6 == nil || len(got.IPv4)+len(got.IPv6) != 0 {
		t.Errorf("got %+v", got)
	}
}

func TestReadAddressesRunsOnThisMachine(t *testing.T) {
	got := ReadAddresses()
	if got.IPv4 == nil || got.IPv6 == nil {
		t.Errorf("lists must not be null: %+v", got)
	}
}
