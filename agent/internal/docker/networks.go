package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/url"
	"sort"
	"strings"

	"commypanel/agent/internal/validate"
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

// RemoveNetwork deletes one of the per-app networks the agent created, once
// nothing is attached to it. The shared network from earlier releases, networks
// that did not come from the agent and anything else are refused, and a network
// that no longer exists is not an error.
func (m *Manager) RemoveNetwork(ctx context.Context, name string) error {
	if err := validate.Network(name); err != nil {
		return err
	}
	if name == defaultNetwork {
		return &validate.InputError{Msg: "the shared network is not removed here"}
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would remove network", "name", name)
		return nil
	}
	var inspect struct {
		Labels map[string]string `json:"Labels"`
	}
	err := m.engine.doJSON(ctx, "GET", "/networks/"+url.PathEscape(name), nil, nil, &inspect)
	if isStatus(err, 404) {
		return nil
	}
	if err != nil {
		return err
	}
	if inspect.Labels[ManagedLabel] != "true" {
		return &validate.InputError{Msg: fmt.Sprintf("network %q was not created by CommyPanel", name)}
	}
	err = m.engine.doJSON(ctx, "DELETE", "/networks/"+url.PathEscape(name), nil, nil, nil)
	if isStatus(err, 404) {
		return nil
	}
	if isStatus(err, 403) || isStatus(err, 409) {
		return &validate.InputError{Msg: fmt.Sprintf("network %q still has containers attached", name)}
	}
	return err
}

// ConnectNetwork attaches a running managed container to a link network, with
// the names it answers to there. The network is created if it is not there yet.
// Joining again is not an error.
func (m *Manager) ConnectNetwork(ctx context.Context, container, network string, aliases []string) error {
	if err := validate.Name(container); err != nil {
		return err
	}
	if err := validate.LinkNetwork(network); err != nil {
		return err
	}
	if len(aliases) > maxAliases {
		return &validate.InputError{Msg: "too many network aliases"}
	}
	for _, a := range aliases {
		if err := validate.Alias(a); err != nil {
			return err
		}
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would connect", "container", container, "network", network)
		return nil
	}
	if exists, err := m.requireManaged(ctx, container); err != nil {
		return err
	} else if !exists {
		return &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", container)}
	}
	if err := m.ensureNetwork(ctx, network); err != nil {
		return err
	}
	body := map[string]any{"Container": container}
	if len(aliases) > 0 {
		body["EndpointConfig"] = map[string]any{"Aliases": aliases}
	}
	err := m.engine.doJSON(ctx, "POST", "/networks/"+url.PathEscape(network)+"/connect", nil, body, nil)
	if err != nil && strings.Contains(err.Error(), "already exists") {
		return nil
	}
	return err
}

// DisconnectNetwork detaches a managed container from a link network. A container
// that is not attached, or a network that is gone, is not an error.
func (m *Manager) DisconnectNetwork(ctx context.Context, container, network string) error {
	if err := validate.Name(container); err != nil {
		return err
	}
	if err := validate.LinkNetwork(network); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would disconnect", "container", container, "network", network)
		return nil
	}
	if exists, err := m.requireManaged(ctx, container); err != nil || !exists {
		return err
	}
	err := m.engine.doJSON(ctx, "POST", "/networks/"+url.PathEscape(network)+"/disconnect", nil, map[string]any{"Container": container, "Force": true}, nil)
	if isStatus(err, 404) || (err != nil && strings.Contains(err.Error(), "is not connected")) {
		return nil
	}
	return err
}

// PruneLinkNetworks removes the link networks nothing is attached to any more
// and returns their names. Other networks are never touched.
func (m *Manager) PruneLinkNetworks(ctx context.Context) ([]string, error) {
	if m.cfg.DryRun {
		return []string{}, nil
	}
	all, err := m.Networks(ctx)
	if err != nil {
		return nil, err
	}
	removed := []string{}
	for _, n := range all {
		if !strings.HasPrefix(n.Name, validate.LinkNetworkPrefix) || len(n.Containers) > 0 {
			continue
		}
		if err := m.RemoveNetwork(ctx, n.Name); err != nil {
			slog.Warn("could not remove an unused link network", "name", n.Name, "err", err)
			continue
		}
		removed = append(removed, n.Name)
	}
	return removed, nil
}
