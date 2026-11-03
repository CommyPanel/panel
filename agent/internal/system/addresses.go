package system

import (
	"net"
	"net/netip"
	"sort"
)

// Addresses are the public IP addresses this machine has on its own network
// interfaces, which is where most servers (Hetzner, DigitalOcean, bare metal)
// carry them. Behind a NAT (a cloud that maps a public IP to a private one) there
// are none, and the panel looks elsewhere.
type Addresses struct {
	IPv4 []string `json:"ipv4"`
	IPv6 []string `json:"ipv6"`
	// Primary is the address the machine would use to reach the internet, when it
	// is one of the public ones above.
	PrimaryIPv4 string `json:"primary_ipv4"`
	PrimaryIPv6 string `json:"primary_ipv6"`
}

// ReadAddresses collects them from the interfaces. The "primary" addresses come
// from asking the kernel which source address it would pick for a route to a public
// resolver; connecting a UDP socket sends nothing.
func ReadAddresses() Addresses {
	var addrs []net.Addr
	if list, err := net.InterfaceAddrs(); err == nil {
		addrs = list
	}
	return PublicAddresses(addrs, routeSource("udp4", "1.1.1.1:53"), routeSource("udp6", "[2606:4700:4700::1111]:53"))
}

func routeSource(network, remote string) netip.Addr {
	conn, err := net.Dial(network, remote)
	if err != nil {
		return netip.Addr{}
	}
	defer conn.Close()
	if udp, ok := conn.LocalAddr().(*net.UDPAddr); ok {
		if ip, ok := netip.AddrFromSlice(udp.IP); ok {
			return ip.Unmap()
		}
	}
	return netip.Addr{}
}

// PublicAddresses keeps the addresses that can be reached from the internet:
// not loopback, link-local, private (10/8, 172.16/12, 192.168/16, fc00::/7),
// carrier-grade NAT (100.64/10), multicast or documentation ranges. Container
// bridges and VPNs use private ranges, so they drop out here.
func PublicAddresses(addrs []net.Addr, primary4, primary6 netip.Addr) Addresses {
	out := Addresses{IPv4: []string{}, IPv6: []string{}}
	seen := map[netip.Addr]bool{}

	for _, a := range addrs {
		prefix, err := netip.ParsePrefix(a.String())
		if err != nil {
			continue
		}
		ip := prefix.Addr().Unmap()
		if !isPublic(ip) || seen[ip] {
			continue
		}
		seen[ip] = true
		if ip.Is4() {
			out.IPv4 = append(out.IPv4, ip.String())
		} else {
			out.IPv6 = append(out.IPv6, ip.String())
		}
	}
	sort.Strings(out.IPv4)
	sort.Strings(out.IPv6)

	if seen[primary4] {
		out.PrimaryIPv4 = primary4.String()
	}
	if seen[primary6] {
		out.PrimaryIPv6 = primary6.String()
	}
	return out
}

var notPublic = []netip.Prefix{
	netip.MustParsePrefix("100.64.0.0/10"),   // carrier-grade NAT
	netip.MustParsePrefix("192.0.0.0/24"),    // IETF protocol assignments
	netip.MustParsePrefix("192.0.2.0/24"),    // documentation
	netip.MustParsePrefix("198.18.0.0/15"),   // benchmarking
	netip.MustParsePrefix("198.51.100.0/24"), // documentation
	netip.MustParsePrefix("203.0.113.0/24"),  // documentation
	netip.MustParsePrefix("240.0.0.0/4"),     // reserved
	netip.MustParsePrefix("2001:db8::/32"),   // documentation
	netip.MustParsePrefix("fc00::/7"),        // unique local
}

func isPublic(ip netip.Addr) bool {
	if !ip.IsValid() || ip.IsUnspecified() || ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() ||
		ip.IsLinkLocalMulticast() || ip.IsMulticast() || ip.IsInterfaceLocalMulticast() {
		return false
	}
	for _, p := range notPublic {
		if p.Contains(ip) {
			return false
		}
	}
	return true
}
