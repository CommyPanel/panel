package docker

import (
	"context"
	"encoding/json"
	"net/url"
	"strings"
	"time"
)

// Info is a summary of the Docker daemon for the panel's Server page. When
// the daemon cannot be reached, Available is false and Error says why, so the
// page can show the problem instead of failing.
type Info struct {
	Available     bool   `json:"available"`
	Error         string `json:"error,omitempty"`
	ServerVersion string `json:"server_version,omitempty"`
	APIVersion    string `json:"api_version,omitempty"`
	OS            string `json:"os,omitempty"`
	Arch          string `json:"arch,omitempty"`
	KernelVersion string `json:"kernel_version,omitempty"`
	StorageDriver string `json:"storage_driver,omitempty"`
	CgroupDriver  string `json:"cgroup_driver,omitempty"`
	Containers    int    `json:"containers"`
	Running       int    `json:"running"`
	Paused        int    `json:"paused"`
	Stopped       int    `json:"stopped"`
	Images        int    `json:"images"`
	CPUs          int    `json:"cpus"`
	MemTotal      uint64 `json:"mem_total_bytes"`
}

// Ping reports whether the daemon answers. A socket file alone proves
// nothing: with systemd socket activation it exists while dockerd is down.
// In dry-run mode Docker is never contacted and counts as available.
func (m *Manager) Ping(ctx context.Context) error {
	if m.cfg.DryRun {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	return m.engine.doJSON(ctx, "GET", "/_ping", nil, nil, nil)
}

// Info asks the daemon for its version and counters.
func (m *Manager) Info(ctx context.Context) (Info, error) {
	if m.cfg.DryRun {
		return Info{Error: "dry-run: Docker is not contacted"}, nil
	}

	var version struct {
		Version       string `json:"Version"`
		APIVersion    string `json:"ApiVersion"`
		OS            string `json:"Os"`
		Arch          string `json:"Arch"`
		KernelVersion string `json:"KernelVersion"`
	}
	if err := m.engine.doJSON(ctx, "GET", "/version", nil, nil, &version); err != nil {
		return Info{Error: err.Error()}, nil
	}

	var info struct {
		Containers   int    `json:"Containers"`
		Running      int    `json:"ContainersRunning"`
		Paused       int    `json:"ContainersPaused"`
		Stopped      int    `json:"ContainersStopped"`
		Images       int    `json:"Images"`
		Driver       string `json:"Driver"`
		CgroupDriver string `json:"CgroupDriver"`
		NCPU         int    `json:"NCPU"`
		MemTotal     uint64 `json:"MemTotal"`
	}
	if err := m.engine.doJSON(ctx, "GET", "/info", nil, nil, &info); err != nil {
		return Info{Error: err.Error()}, nil
	}

	return Info{
		Available: true, ServerVersion: version.Version, APIVersion: version.APIVersion,
		OS: version.OS, Arch: version.Arch, KernelVersion: version.KernelVersion,
		StorageDriver: info.Driver, CgroupDriver: info.CgroupDriver,
		Containers: info.Containers, Running: info.Running, Paused: info.Paused, Stopped: info.Stopped,
		Images: info.Images, CPUs: info.NCPU, MemTotal: info.MemTotal,
	}, nil
}

// ImageInfo describes an image the agent built.
type ImageInfo struct {
	Tags    []string `json:"tags"`
	Size    int64    `json:"size_bytes"`
	Created int64    `json:"created"`
}

// ListImages returns the images in the commypanel/ namespace, newest first.
func (m *Manager) ListImages(ctx context.Context) ([]ImageInfo, error) {
	if m.cfg.DryRun {
		return []ImageInfo{}, nil
	}

	var raw []struct {
		RepoTags []string `json:"RepoTags"`
		Size     int64    `json:"Size"`
		Created  int64    `json:"Created"`
	}
	filters, _ := json.Marshal(map[string][]string{"reference": {buildNamePrefix + "*"}})
	if err := m.engine.doJSON(ctx, "GET", "/images/json", url.Values{"filters": {string(filters)}}, nil, &raw); err != nil {
		return nil, err
	}

	out := make([]ImageInfo, 0, len(raw))
	for _, image := range raw {
		var tags []string
		for _, tag := range image.RepoTags {
			if strings.HasPrefix(tag, buildNamePrefix) {
				tags = append(tags, tag)
			}
		}
		if len(tags) > 0 {
			out = append(out, ImageInfo{Tags: tags, Size: image.Size, Created: image.Created})
		}
	}

	// Newest first, without importing sort for one comparison.
	for i := 1; i < len(out); i++ {
		for j := i; j > 0 && out[j].Created > out[j-1].Created; j-- {
			out[j], out[j-1] = out[j-1], out[j]
		}
	}

	return out, nil
}
