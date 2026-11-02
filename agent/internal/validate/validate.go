// Package validate holds the allow-list checks every agent action runs on its
// inputs. The agent never trusts the caller, even though the caller is the panel.
package validate

import (
	"fmt"
	"path"
	"path/filepath"
	"regexp"
	"strings"
)

// InputError marks a problem with caller-supplied input (HTTP 400), as opposed
// to a failure of the host operation itself.
type InputError struct{ Msg string }

func (e *InputError) Error() string { return e.Msg }

func invalid(format string, args ...any) error {
	return &InputError{Msg: fmt.Sprintf(format, args...)}
}

var (
	nameRe       = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$`)
	volumeNameRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$`)
	labelRe      = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
)

// Name checks a resource name (site, container, certificate).
func Name(s string) error {
	if !nameRe.MatchString(s) {
		return invalid("invalid name %q", s)
	}
	return nil
}

// NetworkPrefix starts the name of every network the agent creates: the shared
// one from earlier releases ("commypanel") and one per app ("commypanel-<app>").
const NetworkPrefix = "commypanel"

// Network checks the name of a Docker network for a container. Only networks of
// our own are accepted, so a request can never name "host" (the host's own
// network stack), "bridge" (shared with everything else Docker runs), "none" or
// another container's namespace.
func Network(s string) error {
	if s == NetworkPrefix {
		return nil
	}
	if !strings.HasPrefix(s, NetworkPrefix+"-") || !nameRe.MatchString(s) {
		return invalid("invalid network %q", s)
	}
	return nil
}

// Domain checks a lowercase DNS hostname with at least two labels.
func Domain(s string) error {
	if len(s) == 0 || len(s) > 253 {
		return invalid("invalid domain length")
	}
	labels := strings.Split(s, ".")
	if len(labels) < 2 {
		return invalid("invalid domain %q", s)
	}
	for _, l := range labels {
		if !labelRe.MatchString(l) {
			return invalid("invalid domain %q", s)
		}
	}
	return nil
}

// Port checks an unprivileged TCP port.
func Port(p int) error {
	if p < 1024 || p > 65535 {
		return invalid("invalid port %d", p)
	}
	return nil
}

var (
	imageRe   = regexp.MustCompile(`^(?:[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*/)*[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*(?::[A-Za-z0-9_][A-Za-z0-9._-]{0,127})?$`)
	envKeyRe  = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)
	ctrPathRe = regexp.MustCompile(`^/[A-Za-z0-9._/-]*$`)
	relPathRe = regexp.MustCompile(`^[A-Za-z0-9._][A-Za-z0-9._/-]*$`)
)

// Image checks a (possibly namespaced) image reference with an optional tag.
func Image(s string) error {
	if len(s) > 200 || !imageRe.MatchString(s) {
		return invalid("invalid image %q", s)
	}
	return nil
}

// EnvKey checks an environment variable name.
func EnvKey(s string) error {
	if !envKeyRe.MatchString(s) {
		return invalid("invalid environment variable name %q", s)
	}
	return nil
}

// EnvValue rejects values that cannot be passed safely (NUL bytes, huge values).
func EnvValue(s string) error {
	if len(s) > 32<<10 || strings.ContainsRune(s, 0) {
		return invalid("invalid environment variable value")
	}
	return nil
}

// ContainerPath checks an absolute path inside a container.
func ContainerPath(s string) error {
	if len(s) > 255 || !ctrPathRe.MatchString(s) || strings.Contains(s, "..") || strings.Contains(s, "//") {
		return invalid("invalid container path %q", s)
	}
	return nil
}

// reservedMounts are container locations a volume must never cover: they would
// hide the container's own root, devices or kernel interfaces.
var reservedMounts = []string{"/proc", "/sys", "/dev"}

// VolumeTarget checks where a persistent volume is mounted inside a container.
func VolumeTarget(s string) error {
	if err := ContainerPath(s); err != nil {
		return err
	}
	clean := path.Clean(s)
	if clean == "/" {
		return invalid("a volume cannot be mounted at the root of the container")
	}
	for _, r := range reservedMounts {
		if clean == r || strings.HasPrefix(clean, r+"/") {
			return invalid("a volume cannot be mounted under %s", r)
		}
	}
	return nil
}

// VolumeName checks the name of one of an app's volumes (a folder name).
func VolumeName(s string) error {
	if !volumeNameRe.MatchString(s) {
		return invalid("invalid volume name %q", s)
	}
	return nil
}

// RelativePath checks a relative path with no traversal (e.g. a Dockerfile).
func RelativePath(s string) error {
	if len(s) > 255 || !relPathRe.MatchString(s) || strings.Contains(s, "..") || strings.Contains(s, "//") {
		return invalid("invalid path %q", s)
	}
	return nil
}

// ResolveUnder returns the cleaned absolute path p, requiring it to be a
// strict descendant of base, including after resolving symlinks for any part
// that already exists. It is what keeps mounts and build contexts inside the
// panel data directory.
func ResolveUnder(base, p string) (string, error) {
	if !filepath.IsAbs(p) {
		return "", invalid("path must be absolute")
	}
	clean := filepath.Clean(p)
	if !within(base, clean) {
		return "", invalid("path %q is outside the allowed directory", p)
	}
	realBase, err := filepath.EvalSymlinks(base)
	if err != nil {
		return clean, nil // base does not exist yet, so nothing inside it can be a symlink
	}
	// Resolve the deepest ancestor that exists; a missing tail cannot be a symlink.
	existing := clean
	for {
		if real, err := filepath.EvalSymlinks(existing); err == nil {
			if real != realBase && !within(realBase, real) {
				return "", invalid("path %q is outside the allowed directory", p)
			}
			break
		}
		parent := filepath.Dir(existing)
		if parent == existing {
			break
		}
		existing = parent
	}
	return clean, nil
}

func within(base, p string) bool {
	rel, err := filepath.Rel(filepath.Clean(base), p)
	return err == nil && rel != "." && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}
