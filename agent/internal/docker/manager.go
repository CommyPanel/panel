package docker

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/url"
	"os"
	"strconv"
	"strings"

	"commypanel/agent/internal/validate"
)

// ManagedLabel marks every container the agent creates. Stop and remove
// refuse to touch containers without it.
const ManagedLabel = "com.commypanel.managed"

const (
	defaultNetwork  = "commypanel"
	maxLogBytes     = 1 << 20
	maxEnvVars      = 200
	maxEnvTotal     = 64 << 10
	maxVolumes      = 16
	minMemoryMB     = 16
	maxMemoryMB     = 1 << 20
	maxCPUs         = 256
	buildNamePrefix = "commypanel/"
)

// Config sets the directories and limits the manager enforces.
type Config struct {
	DataDir         string  // mounts and build contexts must live under here
	DryRun          bool    // validate and log only; never call Docker
	DefaultMemoryMB int     // applied when a spec does not set a limit
	DefaultCPUs     float64 // applied when a spec does not set a limit
	BuildMemoryMB   int
	BuildCPUs       float64
}

func (c Config) withDefaults() Config {
	if c.DefaultMemoryMB == 0 {
		c.DefaultMemoryMB = 512
	}
	if c.DefaultCPUs == 0 {
		c.DefaultCPUs = 1
	}
	if c.BuildMemoryMB == 0 {
		c.BuildMemoryMB = 2048
	}
	if c.BuildCPUs == 0 {
		c.BuildCPUs = 2
	}
	return c
}

// Volume is a bind mount. Source must be inside <DataDir>/volumes.
type Volume struct {
	Source   string `json:"source"`
	Target   string `json:"target"`
	ReadOnly bool   `json:"read_only"`
}

// ContainerSpec is everything the panel may decide about a container. All
// security-relevant settings (privileges, capabilities, networking, labels)
// are fixed by the agent.
type ContainerSpec struct {
	Name          string            `json:"name"`
	Image         string            `json:"image"`
	HostPort      int               `json:"host_port,omitempty"` // published on 127.0.0.1 only; 0 = not published
	ContainerPort int               `json:"container_port,omitempty"`
	Env           map[string]string `json:"env,omitempty"`
	Volumes       []Volume          `json:"volumes,omitempty"`
	MemoryMB      int               `json:"memory_mb,omitempty"`
	CPUs          float64           `json:"cpus,omitempty"`
	Network       string            `json:"network,omitempty"`
}

// ContainerInfo describes a managed container.
type ContainerInfo struct {
	Name    string `json:"name"`
	Image   string `json:"image"`
	State   string `json:"state"`
	Status  string `json:"status"`
	Created int64  `json:"created"`
}

// Manager runs the Docker actions.
type Manager struct {
	cfg    Config
	engine *Engine
	build  chan struct{} // one image build at a time
}

func NewManager(cfg Config, engine *Engine) *Manager {
	return &Manager{cfg: cfg.withDefaults(), engine: engine, build: make(chan struct{}, 1)}
}

func (m *Manager) volumesDir() string { return m.cfg.DataDir + "/volumes" }
func (m *Manager) buildsDir() string  { return m.cfg.DataDir + "/builds" }

// Validate checks a container spec without touching Docker.
func (m *Manager) Validate(s ContainerSpec) error {
	if err := validate.Name(s.Name); err != nil {
		return err
	}
	if err := validate.Image(s.Image); err != nil {
		return err
	}
	if s.HostPort != 0 {
		if err := validate.Port(s.HostPort); err != nil {
			return err
		}
		if s.ContainerPort < 1 || s.ContainerPort > 65535 {
			return &validate.InputError{Msg: "container_port is required when host_port is set"}
		}
	} else if s.ContainerPort != 0 {
		return &validate.InputError{Msg: "container_port requires host_port"}
	}
	if len(s.Env) > maxEnvVars {
		return &validate.InputError{Msg: "too many environment variables"}
	}
	total := 0
	for k, v := range s.Env {
		if err := validate.EnvKey(k); err != nil {
			return err
		}
		if err := validate.EnvValue(v); err != nil {
			return err
		}
		if total += len(k) + len(v); total > maxEnvTotal {
			return &validate.InputError{Msg: "environment is too large"}
		}
	}
	if len(s.Volumes) > maxVolumes {
		return &validate.InputError{Msg: "too many volumes"}
	}
	for _, v := range s.Volumes {
		if _, err := validate.ResolveUnder(m.volumesDir(), v.Source); err != nil {
			return err
		}
		if err := validate.ContainerPath(v.Target); err != nil {
			return err
		}
	}
	if s.MemoryMB != 0 && (s.MemoryMB < minMemoryMB || s.MemoryMB > maxMemoryMB) {
		return &validate.InputError{Msg: "invalid memory_mb"}
	}
	if s.CPUs != 0 && (s.CPUs < 0.05 || s.CPUs > maxCPUs) {
		return &validate.InputError{Msg: "invalid cpus"}
	}
	if s.Network != "" {
		return validate.Name(s.Network)
	}
	return nil
}

// createBody builds the Engine API payload. Anything security-relevant is
// hard-coded here and cannot be influenced by the caller.
func (m *Manager) createBody(s ContainerSpec) map[string]any {
	env := make([]string, 0, len(s.Env))
	for k, v := range s.Env {
		env = append(env, k+"="+v)
	}
	memMB, cpus := s.MemoryMB, s.CPUs
	if memMB == 0 {
		memMB = m.cfg.DefaultMemoryMB
	}
	if cpus == 0 {
		cpus = m.cfg.DefaultCPUs
	}
	network := s.Network
	if network == "" {
		network = defaultNetwork
	}

	binds := make([]string, 0, len(s.Volumes))
	for _, v := range s.Volumes {
		bind := v.Source + ":" + v.Target
		if v.ReadOnly {
			bind += ":ro"
		}
		binds = append(binds, bind)
	}

	host := map[string]any{
		"Privileged":      false,
		"Binds":           binds,
		"Memory":          int64(memMB) << 20,
		"NanoCpus":        int64(cpus * 1e9),
		"PidsLimit":       512,
		"RestartPolicy":   map[string]any{"Name": "unless-stopped"},
		"SecurityOpt":     []string{"no-new-privileges:true"},
		"CapDrop":         []string{"NET_RAW"},
		"NetworkMode":     network,
		"PublishAllPorts": false,
	}
	body := map[string]any{
		"Image":      s.Image,
		"Env":        env,
		"Labels":     map[string]string{ManagedLabel: "true"},
		"HostConfig": host,
	}
	if s.HostPort != 0 {
		port := strconv.Itoa(s.ContainerPort) + "/tcp"
		body["ExposedPorts"] = map[string]any{port: struct{}{}}
		host["PortBindings"] = map[string]any{port: []map[string]string{{"HostIp": "127.0.0.1", "HostPort": strconv.Itoa(s.HostPort)}}}
	}
	return body
}

// Run creates and starts a container.
func (m *Manager) Run(ctx context.Context, s ContainerSpec) error {
	if err := m.Validate(s); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would run container", "name", s.Name, "image", s.Image)
		return nil
	}
	network := s.Network
	if network == "" {
		network = defaultNetwork
	}
	if err := m.ensureNetwork(ctx, network); err != nil {
		return err
	}
	for _, v := range s.Volumes {
		if err := os.MkdirAll(v.Source, 0o755); err != nil {
			return fmt.Errorf("create volume dir: %w", err)
		}
	}
	if err := m.engine.doJSON(ctx, "POST", "/containers/create", url.Values{"name": {s.Name}}, m.createBody(s), nil); err != nil {
		return err
	}
	if err := m.engine.doJSON(ctx, "POST", "/containers/"+s.Name+"/start", nil, nil, nil); err != nil {
		_ = m.engine.doJSON(context.WithoutCancel(ctx), "DELETE", "/containers/"+s.Name, url.Values{"force": {"true"}}, nil, nil)
		return err
	}
	slog.Info("container started", "name", s.Name, "image", s.Image)
	return nil
}

func (m *Manager) ensureNetwork(ctx context.Context, name string) error {
	err := m.engine.doJSON(ctx, "GET", "/networks/"+name, nil, nil, nil)
	if !isStatus(err, 404) {
		return err
	}
	return m.engine.doJSON(ctx, "POST", "/networks/create", nil, map[string]any{
		"Name": name, "Driver": "bridge", "Labels": map[string]string{ManagedLabel: "true"},
	}, nil)
}

// requireManaged returns false when the container does not exist, and an
// error when it exists but was not created by the agent.
func (m *Manager) requireManaged(ctx context.Context, name string) (bool, error) {
	var inspect struct {
		Config struct {
			Labels map[string]string `json:"Labels"`
		} `json:"Config"`
	}
	err := m.engine.doJSON(ctx, "GET", "/containers/"+name+"/json", nil, nil, &inspect)
	if isStatus(err, 404) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if inspect.Config.Labels[ManagedLabel] != "true" {
		return false, &validate.InputError{Msg: fmt.Sprintf("container %q is not managed by CommyPanel", name)}
	}
	return true, nil
}

// Stop stops a managed container. Stopping a missing or stopped one succeeds.
func (m *Manager) Stop(ctx context.Context, name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would stop container", "name", name)
		return nil
	}
	if exists, err := m.requireManaged(ctx, name); err != nil || !exists {
		return err
	}
	err := m.engine.doJSON(ctx, "POST", "/containers/"+name+"/stop", url.Values{"t": {"10"}}, nil, nil)
	if isStatus(err, 404) {
		return nil
	}
	return err
}

// Start starts a stopped managed container. Starting a running one succeeds.
func (m *Manager) Start(ctx context.Context, name string) error {
	if err := m.requireExisting(ctx, name, "start"); err != nil {
		return err
	}
	if m.cfg.DryRun {
		return nil
	}
	// Docker answers 304 for an already running container, which is not an error.
	return m.engine.doJSON(ctx, "POST", "/containers/"+name+"/start", nil, nil, nil)
}

// Restart stops (with a grace period) and starts a managed container.
func (m *Manager) Restart(ctx context.Context, name string) error {
	if err := m.requireExisting(ctx, name, "restart"); err != nil {
		return err
	}
	if m.cfg.DryRun {
		return nil
	}
	return m.engine.doJSON(ctx, "POST", "/containers/"+name+"/restart", url.Values{"t": {"10"}}, nil, nil)
}

// requireExisting validates the name and, outside dry-run, insists the
// container exists and is managed.
func (m *Manager) requireExisting(ctx context.Context, name, action string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would "+action+" container", "name", name)
		return nil
	}
	exists, err := m.requireManaged(ctx, name)
	if err != nil {
		return err
	}
	if !exists {
		return &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", name)}
	}
	return nil
}

// Remove force-removes a managed container, keeping its volumes' data.
func (m *Manager) Remove(ctx context.Context, name string) error {
	if err := validate.Name(name); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would remove container", "name", name)
		return nil
	}
	if exists, err := m.requireManaged(ctx, name); err != nil || !exists {
		return err
	}
	err := m.engine.doJSON(ctx, "DELETE", "/containers/"+name, url.Values{"force": {"true"}, "v": {"false"}}, nil, nil)
	if isStatus(err, 404) {
		return nil
	}
	return err
}

// List returns the containers the agent manages.
func (m *Manager) List(ctx context.Context) ([]ContainerInfo, error) {
	if m.cfg.DryRun {
		return []ContainerInfo{}, nil
	}
	filters, _ := json.Marshal(map[string][]string{"label": {ManagedLabel + "=true"}})
	var raw []struct {
		Names   []string `json:"Names"`
		Image   string   `json:"Image"`
		State   string   `json:"State"`
		Status  string   `json:"Status"`
		Created int64    `json:"Created"`
	}
	if err := m.engine.doJSON(ctx, "GET", "/containers/json", url.Values{"all": {"true"}, "filters": {string(filters)}}, nil, &raw); err != nil {
		return nil, err
	}
	out := make([]ContainerInfo, 0, len(raw))
	for _, c := range raw {
		name := ""
		if len(c.Names) > 0 {
			name = strings.TrimPrefix(c.Names[0], "/")
		}
		out = append(out, ContainerInfo{Name: name, Image: c.Image, State: c.State, Status: c.Status, Created: c.Created})
	}
	return out, nil
}

// Logs returns the last tail lines of a managed container's output.
func (m *Manager) Logs(ctx context.Context, name string, tail int) (string, error) {
	if err := validate.Name(name); err != nil {
		return "", err
	}
	if tail < 1 || tail > 5000 {
		return "", &validate.InputError{Msg: "tail must be between 1 and 5000"}
	}
	if m.cfg.DryRun {
		return "", nil
	}
	if exists, err := m.requireManaged(ctx, name); err != nil {
		return "", err
	} else if !exists {
		return "", &validate.InputError{Msg: fmt.Sprintf("container %q does not exist", name)}
	}
	resp, err := m.engine.do(ctx, "GET", "/containers/"+name+"/logs",
		url.Values{"stdout": {"1"}, "stderr": {"1"}, "tail": {strconv.Itoa(tail)}}, nil, "")
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	return demux(io.LimitReader(resp.Body, maxLogBytes*8)), nil
}

// demux strips Docker's 8-byte stream headers from a non-TTY log stream. When
// the output is larger than maxLogBytes it keeps the end, because the newest
// lines are the ones people look for.
func demux(r io.Reader) string {
	var out bytes.Buffer
	header := make([]byte, 8)
	for {
		if _, err := io.ReadFull(r, header); err != nil {
			break
		}
		size := int64(binary.BigEndian.Uint32(header[4:]))
		if _, err := io.CopyN(&out, r, size); err != nil {
			break
		}
	}
	data := out.Bytes()
	if len(data) > maxLogBytes {
		data = data[len(data)-maxLogBytes:]
		// Start on a whole line.
		if i := bytes.IndexByte(data, '\n'); i >= 0 && i+1 < len(data) {
			data = data[i+1:]
		}
	}
	return string(data)
}

// PruneImages removes dangling images and reports the space reclaimed.
func (m *Manager) PruneImages(ctx context.Context) (uint64, error) {
	if m.cfg.DryRun {
		return 0, nil
	}
	filters, _ := json.Marshal(map[string][]string{"dangling": {"true"}})
	var out struct {
		SpaceReclaimed uint64 `json:"SpaceReclaimed"`
	}
	if err := m.engine.doJSON(ctx, "POST", "/images/prune", url.Values{"filters": {string(filters)}}, nil, &out); err != nil {
		return 0, err
	}
	return out.SpaceReclaimed, nil
}

// RemoveImage deletes one of the images the agent builds. Only tags in the
// commypanel/ namespace are accepted; a missing image is not an error.
func (m *Manager) RemoveImage(ctx context.Context, tag string) error {
	if !strings.HasPrefix(tag, buildNamePrefix) {
		return &validate.InputError{Msg: "only " + buildNamePrefix + " images can be removed"}
	}
	if err := validate.Image(tag); err != nil {
		return err
	}
	if m.cfg.DryRun {
		slog.Info("dry-run: would remove image", "tag", tag)
		return nil
	}
	err := m.engine.doJSON(ctx, "DELETE", "/images/"+tag, url.Values{"force": {"false"}}, nil, nil)
	if isStatus(err, 404) {
		return nil
	}
	return err
}
