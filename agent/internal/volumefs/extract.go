package volumefs

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path"
	"path/filepath"
	"strings"
	"syscall"

	"commypanel/agent/internal/validate"
)

// Summary is what an archive holds, or what a restore wrote.
type Summary struct {
	Files int   `json:"files"`
	Dirs  int   `json:"-"`
	Bytes int64 `json:"bytes"`
}

const (
	maxEntryName = 4096
	maxOwnerID   = 1<<32 - 2
	// Gzip may expand without end, so what is read from it is capped a little
	// above what the content limit and the tar headers can legitimately need.
	maxTarStream = MaxArchiveBytes + MaxArchiveEntries*1536
)

func bad(format string, args ...any) error {
	return &validate.InputError{Msg: "the archive is not safe to restore: " + fmt.Sprintf(format, args...)}
}

// CheckEntry decides whether one archive entry may be restored and returns its
// clean path relative to the volume. Only folders and plain files are allowed
// (a link, pipe, device or sparse file never is), the path must stay inside the
// volume and the owner must be a real numeric id. skip is true for the volume's
// own folder ("./"), which carries nothing.
func CheckEntry(h *tar.Header) (name string, dir, skip bool, err error) {
	switch h.Typeflag {
	case tar.TypeDir:
		dir = true
	case tar.TypeReg:
	default:
		return "", false, false, bad("%q is a link or special file", h.Name)
	}
	raw := h.Name
	if raw == "" || len(raw) > maxEntryName || strings.ContainsAny(raw, "\x00\\") || strings.HasPrefix(raw, "/") {
		return "", false, false, bad("the path %q is not allowed", abbreviate(raw))
	}
	for _, part := range strings.Split(raw, "/") {
		if part == ".." {
			return "", false, false, bad("the path %q leaves the volume", abbreviate(raw))
		}
	}
	if !dir && strings.HasSuffix(raw, "/") {
		return "", false, false, bad("the file %q has a folder's name", abbreviate(raw))
	}
	clean := path.Clean(raw)
	if clean == "." {
		if dir {
			return "", true, true, nil
		}
		return "", false, false, bad("a file cannot be the volume itself")
	}
	if h.Size < 0 || (!dir && h.Size > MaxArchiveBytes) {
		return "", false, false, bad("%q has an impossible size", abbreviate(raw))
	}
	if h.Uid < 0 || h.Gid < 0 || h.Uid > maxOwnerID || h.Gid > maxOwnerID {
		return "", false, false, bad("%q has an impossible owner", abbreviate(raw))
	}
	return clean, dir, false, nil
}

func abbreviate(s string) string {
	if len(s) > 80 {
		return s[:80] + "..."
	}
	return strings.ToValidUTF8(s, "?")
}

func openTar(r io.Reader) (*tar.Reader, func(), error) {
	zr, err := gzip.NewReader(r)
	if err != nil {
		return nil, nil, &validate.InputError{Msg: "the archive is not a valid gzip file"}
	}
	return tar.NewReader(io.LimitReader(zr, maxTarStream)), func() { zr.Close() }, nil
}

// Scan reads a whole archive without writing anything, applying every rule of
// a restore, and says how much it holds. A restore scans first so that an
// unsafe or too big archive is refused before the volume is touched, and so the
// disk space needed is known.
func Scan(ctx context.Context, r io.Reader) (Summary, error) {
	tr, closeZ, err := openTar(r)
	if err != nil {
		return Summary{}, err
	}
	defer closeZ()
	var sum Summary
	entries := 0
	for {
		if err := ctx.Err(); err != nil {
			return Summary{}, err
		}
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return sum, nil
		}
		if err != nil {
			return Summary{}, &validate.InputError{Msg: "the archive is damaged: " + abbreviate(err.Error())}
		}
		if entries++; entries > MaxArchiveEntries {
			return Summary{}, &validate.InputError{Msg: ErrArchiveTooBig.Error()}
		}
		_, dir, skip, err := CheckEntry(h)
		if err != nil {
			return Summary{}, err
		}
		if skip {
			continue
		}
		if dir {
			sum.Dirs++
			continue
		}
		if sum.Bytes += h.Size; sum.Bytes > MaxArchiveBytes {
			return Summary{}, &validate.InputError{Msg: ErrArchiveTooBig.Error()}
		}
		sum.Files++
	}
}

type dirMeta struct {
	name     string
	mode     os.FileMode
	uid, gid int
}

// Extract writes an archive into dst, which must be an (empty) folder opened as
// an os.Root, so nothing can land outside it whatever the entry names say.
// Files are created exclusively (a duplicate entry is refused), never through
// links, with the numeric owner of the archive when chown is true (the agent
// runs as root; tests do not). Folder modes are applied last so a read-only
// folder does not stop its own content from being written. Setuid, setgid and
// sticky bits are dropped.
func Extract(ctx context.Context, dst *os.Root, r io.Reader, chown bool) (Summary, error) {
	tr, closeZ, err := openTar(r)
	if err != nil {
		return Summary{}, err
	}
	defer closeZ()

	var sum Summary
	var dirs []dirMeta
	entries := 0
	for {
		if err := ctx.Err(); err != nil {
			return Summary{}, err
		}
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return Summary{}, &validate.InputError{Msg: "the archive is damaged: " + abbreviate(err.Error())}
		}
		if entries++; entries > MaxArchiveEntries {
			return Summary{}, &validate.InputError{Msg: ErrArchiveTooBig.Error()}
		}
		name, dir, skip, err := CheckEntry(h)
		if err != nil {
			return Summary{}, err
		}
		if skip {
			continue
		}
		perm := os.FileMode(h.Mode) & 0o777
		if dir {
			if err := dst.MkdirAll(name, 0o700); err != nil {
				return Summary{}, fmt.Errorf("restoring %s: %w", name, err)
			}
			dirs = append(dirs, dirMeta{name, perm, h.Uid, h.Gid})
			sum.Dirs++
			continue
		}
		if parent := path.Dir(name); parent != "." {
			if err := dst.MkdirAll(parent, 0o700); err != nil {
				return Summary{}, fmt.Errorf("restoring %s: %w", name, err)
			}
		}
		if sum.Bytes += h.Size; sum.Bytes > MaxArchiveBytes {
			return Summary{}, &validate.InputError{Msg: ErrArchiveTooBig.Error()}
		}
		f, err := dst.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if err != nil {
			if errors.Is(err, os.ErrExist) {
				return Summary{}, bad("%q appears twice", abbreviate(name))
			}
			return Summary{}, fmt.Errorf("restoring %s: %w", name, err)
		}
		_, err = io.Copy(f, tr)
		if err == nil && chown {
			err = tolerateChown(f.Chown(h.Uid, h.Gid))
		}
		if err == nil {
			err = f.Chmod(perm)
		}
		if cerr := f.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			return Summary{}, fmt.Errorf("restoring %s: %w", name, err)
		}
		sum.Files++
	}

	for i := len(dirs) - 1; i >= 0; i-- {
		d := dirs[i]
		if chown {
			if err := tolerateChown(dst.Chown(d.name, d.uid, d.gid)); err != nil {
				return Summary{}, fmt.Errorf("restoring %s: %w", d.name, err)
			}
		}
		if err := dst.Chmod(d.name, d.mode); err != nil {
			return Summary{}, fmt.Errorf("restoring %s: %w", d.name, err)
		}
	}
	return sum, nil
}

// tolerateChown ignores "not permitted" when the agent is not root (a
// development run on a laptop); as root every failure counts.
func tolerateChown(err error) error {
	if err != nil && os.Geteuid() != 0 && errors.Is(err, syscall.EPERM) {
		return nil
	}
	return err
}

func tempName(prefix string) string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return prefix + hex.EncodeToString(b)
}

// Replace swaps the content of the volume folder <volumes>/<app>/<name> for the
// archive at archivePath. The archive is unpacked into a hidden sibling folder
// first, which is given the old folder's mode and owner, and only when that has
// worked are the two renamed, so a failed restore leaves the old content as it
// was. The folder must exist already.
func Replace(ctx context.Context, volumes, app, name, archivePath string, chown bool) (Summary, error) {
	if err := validate.Name(app); err != nil {
		return Summary{}, err
	}
	if err := validate.VolumeName(name); err != nil {
		return Summary{}, err
	}
	parent, err := os.OpenRoot(filepath.Join(volumes, app))
	if err != nil {
		return Summary{}, &validate.InputError{Msg: "this volume does not exist"}
	}
	defer parent.Close()

	old, err := parent.Lstat(name)
	if err != nil || !old.IsDir() {
		return Summary{}, &validate.InputError{Msg: "this volume does not exist"}
	}

	tmp := tempName(".restore-")
	if err := parent.Mkdir(tmp, 0o700); err != nil {
		return Summary{}, err
	}
	swapped := false
	defer func() {
		if !swapped {
			_ = parent.RemoveAll(tmp)
		}
	}()

	f, err := os.Open(archivePath)
	if err != nil {
		return Summary{}, err
	}
	defer f.Close()
	dst, err := parent.OpenRoot(tmp)
	if err != nil {
		return Summary{}, err
	}
	sum, err := Extract(ctx, dst, f, chown)
	dst.Close()
	if err != nil {
		return Summary{}, err
	}

	if st, ok := old.Sys().(*syscall.Stat_t); ok && chown {
		if err := tolerateChown(parent.Chown(tmp, int(st.Uid), int(st.Gid))); err != nil {
			return Summary{}, err
		}
	}
	if err := parent.Chmod(tmp, old.Mode().Perm()); err != nil {
		return Summary{}, err
	}

	aside := tempName(".restore-old-")
	if err := parent.Rename(name, aside); err != nil {
		return Summary{}, err
	}
	if err := parent.Rename(tmp, name); err != nil {
		_ = parent.Rename(aside, name)
		return Summary{}, err
	}
	swapped = true
	if err := parent.RemoveAll(aside); err != nil {
		slog.Warn("could not remove the old content of a restored volume", "app", app, "volume", name, "err", err)
	}
	return sum, nil
}
