package volumefs

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"syscall"
)

const (
	// MaxArchiveBytes is how much file content one archive may hold. A volume
	// bigger than this is refused rather than filling the disk.
	MaxArchiveBytes = 50 << 30
	// MaxArchiveEntries is how many files and folders one archive may hold.
	MaxArchiveEntries = 2_000_000
)

// ErrArchiveTooBig is returned for a volume beyond the limits above.
var ErrArchiveTooBig = errors.New("the volume is too big to back up in one piece")

// Archive writes the volume as a gzipped tar into w. Like everything in a
// volume it is untrusted: it is read through os.Root, only folders and plain
// files are archived (a link, pipe, socket or device is skipped, never followed),
// and each file is checked again after it is opened. Owners and modes are kept
// as numbers, because apps run as users the host does not know, so that a
// restore gives the files back to the same users.
func (b *Browser) Archive(ctx context.Context, app, name string, w io.Writer) error {
	root, err := b.open(app, name)
	if err != nil {
		return err
	}
	defer root.Close()

	gz := gzip.NewWriter(w)
	tw := tar.NewWriter(gz)
	a := &archiver{ctx: ctx, root: root, tw: tw}

	if err := a.walk("."); err != nil {
		return err
	}
	if err := tw.Close(); err != nil {
		return err
	}
	return gz.Close()
}

type archiver struct {
	ctx     context.Context
	root    *os.Root
	tw      *tar.Writer
	entries int
	bytes   int64
}

func (a *archiver) walk(dir string) error {
	if err := a.ctx.Err(); err != nil {
		return err
	}
	d, err := a.root.Open(dir)
	if err != nil {
		return err
	}
	items, err := d.ReadDir(-1)
	d.Close()
	if err != nil {
		return err
	}

	for _, item := range items {
		rel := path.Join(dir, item.Name())
		if dir == "." {
			rel = item.Name()
		}
		info, err := a.root.Lstat(rel)
		if err != nil {
			continue // gone since it was listed
		}
		if a.entries++; a.entries > MaxArchiveEntries {
			return ErrArchiveTooBig
		}

		switch {
		case info.IsDir():
			if err := a.header(rel+"/", info, 0); err != nil {
				return err
			}
			if err := a.walk(rel); err != nil {
				return err
			}
		case info.Mode().IsRegular():
			if err := a.file(rel, info); err != nil {
				return err
			}
		}
	}
	return nil
}

func (a *archiver) file(rel string, listed fs.FileInfo) error {
	f, err := a.root.Open(rel)
	if err != nil {
		return nil // replaced by something that cannot be opened inside the volume
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() {
		return nil // swapped for something else since it was listed
	}
	if a.bytes += info.Size(); a.bytes > MaxArchiveBytes {
		return ErrArchiveTooBig
	}
	if err := a.header(rel, info, info.Size()); err != nil {
		return err
	}
	// A file that grows while it is read is cut at the size announced.
	n, err := io.Copy(a.tw, io.LimitReader(f, info.Size()))
	if err != nil {
		return err
	}
	if n < info.Size() {
		// It shrank: pad, so the archive stays well formed.
		if _, err := io.CopyN(a.tw, zeroes{}, info.Size()-n); err != nil {
			return err
		}
	}
	return nil
}

func (a *archiver) header(name string, info fs.FileInfo, size int64) error {
	hdr, err := tar.FileInfoHeader(info, "")
	if err != nil {
		return err
	}
	hdr.Name = name
	hdr.Size = size
	hdr.Uname, hdr.Gname = "", ""
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		hdr.Uid, hdr.Gid = int(st.Uid), int(st.Gid)
	}
	if err := a.tw.WriteHeader(hdr); err != nil {
		return fmt.Errorf("writing the archive: %w", err)
	}
	return nil
}

type zeroes struct{}

func (zeroes) Read(p []byte) (int, error) { clear(p); return len(p), nil }
