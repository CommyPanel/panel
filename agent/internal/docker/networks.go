package docker

import (
	"context"
	"encoding/json"
	"net/url"
	"sort"
	"strings"
)

// NetworkInfo is a Docker network the agent created, with what is attached to it.
type NetworkInfo struct {
	Name       string          `json:"name"`
	Driver     string          `json:"driver"`
	Subnet     string          `json:"subnet"`
	Gateway    string          `json:"gateway"`
	Internal   bool            `json:"internal"`
	Containers []NetworkMember `json:"containers"`
}

// NetworkMember is a container attached to a network.
type NetworkMember struct {
	Name string `json:"name"`
	IP   string `json:"ip"`
}

// Networks lists the networks the agent manages. Read only.
func (m *Manager) Networks(ctx context.Context) ([]NetworkInfo, error) {
	if m.cfg.DryRun {
		return []NetworkInfo{}, nil
	}
	filters, _ := json.Marshal(map[string][]string{"label": {ManagedLabel + "=true"}})
	var listed []struct {
		ID string `json:"Id"`
	}
	if err := m.engine.doJSON(ctx, "GET", "/networks", url.Values{"filters": {string(filters)}}, nil, &listed); err != nil {
		return nil, err
	}

	out := make([]NetworkInfo, 0, len(listed))
	for _, l := range listed {
		var n struct {
			Name     string `json:"Name"`
			Driver   string `json:"Driver"`
			Internal bool   `json:"Internal"`
			IPAM     struct {
				Config []struct {
					Subnet  string `json:"Subnet"`
					Gateway string `json:"Gateway"`
				} `json:"Config"`
			} `json:"IPAM"`
			Containers map[string]struct {
				Name        string `json:"Name"`
				IPv4Address string `json:"IPv4Address"`
			} `json:"Containers"`
		}
		// Inspect by ID: the listing does not say what is attached.
		if err := m.engine.doJSON(ctx, "GET", "/networks/"+url.PathEscape(l.ID), nil, nil, &n); err != nil {
			if isStatus(err, 404) {
				continue // removed since the listing
			}
			return nil, err
		}
		info := NetworkInfo{Name: n.Name, Driver: n.Driver, Internal: n.Internal, Containers: []NetworkMember{}}
		if len(n.IPAM.Config) > 0 {
			info.Subnet, info.Gateway = n.IPAM.Config[0].Subnet, n.IPAM.Config[0].Gateway
		}
		for _, c := range n.Containers {
			info.Containers = append(info.Containers, NetworkMember{Name: c.Name, IP: strings.SplitN(c.IPv4Address, "/", 2)[0]})
		}
		sort.Slice(info.Containers, func(i, j int) bool { return info.Containers[i].Name < info.Containers[j].Name })
		out = append(out, info)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}
