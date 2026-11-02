package docker

import (
	"archive/tar"
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"commypanel/agent/internal/validate"
)

// BuildSpec describes an image build. The context is a directory the panel
// unpacked under <DataDir>/builds; the agent tars it itself, so remote
// contexts and arbitrary host paths are impossible.
type BuildSpec struct {
	Tag         string `json:"tag"`
	ContextPath string `json:"context_path"`
	Dockerfile  string `json:"dockerfile,omitempty"`
}

// ValidateBuild checks a build spec without touching Docker.
func (m *Manager) ValidateBuild(s BuildSpec) error {
	if !strings.HasPrefix(s.Tag, buildNamePrefix) {
		return &validate.InputError{Msg: "build tags must start with " + buildNamePrefix}
	}
	if err := validate.Image(s.Tag); err != nil {
		return err
	}
	if _, err := validate.ResolveUnder(m.buildsDir(), s.ContextPath); err != nil {
		return err
	}
	if s.Dockerfile != "" {
		return validate.RelativePath(s.Dockerfile)
	}
	return nil
}

// Build builds an image, calling onLine for every line of build output. Only
// one build runs at a time so builds cannot starve the apps on the server.
func (m *Manager) Build(ctx context.Context, s BuildSpec, onLine func(string)) error {
	if err := m.ValidateBuild(s); err != nil {
		return err
	}
	if m.cfg.DryRun {
		onLine("[dry-run] would build " + s.Tag)
		return nil
	}

	select {
	case m.build <- struct{}{}:
		defer func() { <-m.build }()
	case <-ctx.Done():
		return ctx.Err()
	}

	dir := filepath.Clean(s.ContextPath)
	if info, err := os.Stat(dir); err != nil || !info.IsDir() {
		return &validate.InputError{Msg: "build context does not exist"}
	}
	dockerfile := s.Dockerfile
	if dockerfile == "" {
		dockerfile = "Dockerfile"
	}
	if _, err := os.Stat(filepath.Join(dir, dockerfile)); err != nil {
		return &validate.InputError{Msg: "Dockerfile not found in the build context"}
	}

	pr, pw := io.Pipe()
	go func() { pw.CloseWithError(writeTar(pw, dir)) }()

	query := url.Values{
		"t":          {s.Tag},
		"dockerfile": {dockerfile},
		"rm":         {"1"},
		"forcerm":    {"1"},
		"memory":     {strconv.FormatInt(int64(m.cfg.BuildMemoryMB)<<20, 10)},
		"cpuperiod":  {"100000"},
		"cpuquota":   {strconv.FormatInt(int64(m.cfg.BuildCPUs*100000), 10)},
	}
	resp, err := m.engine.do(ctx, "POST", "/build", query, pr, "application/x-tar")
	if err != nil {
		pr.CloseWithError(err)
		return err
	}
	defer resp.Body.Close()

	sc := bufio.NewScanner(resp.Body)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		var msg struct {
			Stream string `json:"stream"`
			Error  string `json:"error"`
		}
		if json.Unmarshal(sc.Bytes(), &msg) != nil {
			continue
		}
		if msg.Error != "" {
			return fmt.Errorf("build failed: %s", strings.TrimSpace(msg.Error))
		}
		if line := strings.TrimRight(msg.Stream, "\r\n"); line != "" {
			onLine(line)
		}
	}
	if err := sc.Err(); err != nil {
		return err
	}
	slog.Info("image built", "tag", s.Tag)
	return nil
}

// writeTar archives dir. Symlinks are stored as symlinks, never followed, so
// a link inside the context cannot pull in files from elsewhere on the host.
func writeTar(w io.Writer, dir string) error {
	// The build folder belongs to the panel user, who could swap a file for a link
	// to a root-only file between the moment it is listed and the moment it is
	// read. Files are therefore opened through a root that cannot be left, and
	// must still be what was listed.
	root, err := os.OpenRoot(dir)
	if err != nil {
		return err
	}
	defer root.Close()

	tw := tar.NewWriter(w)
	err = filepath.WalkDir(dir, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(dir, path)
		if err != nil || rel == "." {
			return err
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		link := ""
		if info.Mode()&fs.ModeSymlink != 0 {
			if link, err = os.Readlink(path); err != nil {
				return err
			}
		}
		hdr, err := tar.FileInfoHeader(info, link)
		if err != nil {
			return err
		}
		hdr.Name = filepath.ToSlash(rel)
		hdr.Uid, hdr.Gid, hdr.Uname, hdr.Gname = 0, 0, "", ""
		if d.IsDir() {
			hdr.Name += "/"
		}
		if !info.Mode().IsRegular() && !d.IsDir() && info.Mode()&fs.ModeSymlink == 0 {
			return nil // skip sockets, devices, pipes
		}
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if !info.Mode().IsRegular() {
			return nil
		}
		f, err := root.Open(rel)
		if err != nil {
			return err
		}
		defer f.Close()
		if st, err := f.Stat(); err != nil || !st.Mode().IsRegular() || st.Size() != hdr.Size {
			return fmt.Errorf("%s changed while the build context was being read", rel)
		}
		_, err = io.Copy(tw, io.LimitReader(f, hdr.Size))
		return err
	})
	if err != nil {
		return err
	}
	return errors.Join(tw.Close())
}
