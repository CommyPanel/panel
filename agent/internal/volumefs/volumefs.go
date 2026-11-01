// Package volumefs lets the panel look into the persistent volumes of apps.
//
// Everything in a volume was written by the app, so it is untrusted: the app
// may have planted links that point at the host's files, named pipes that block
// a reader, or device nodes. The agent runs as root, so every access goes
// through os.Root, which refuses to leave the volume (also through links and
// races), and anything that is not a plain file is never opened for reading.
// Access is read only.
package volumefs

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"

	"commypanel/agent/internal/validate"
)

const (
	// MaxEntries is how many entries of one folder are listed.
	MaxEntries = 2000
	// PreviewLimit is how much of a file is returned for a preview.
	PreviewLimit = 256 << 10
	// MaxDownload is the largest file that can be fetched through the agent.
	MaxDownload = 25 << 20

	sizeEntryLimit = 200_000
	sizeTimeLimit  = 5 * time.Second
	binarySniff    = 8192
)

// Browser reads <dir> (the volumes directory of the data dir).
type Browser struct{ dir string }

func New(dataDir string) *Browser { return &Browser{dir: filepath.Join(dataDir, "volumes")} }

// Volume is one folder of an app's volumes with the space it uses.
type Volume struct {
	Name    string `json:"name"`
	Bytes   int64  `json:"bytes"`
	Files   int    `json:"files"`
	Partial bool   `json:"partial"` // the folder was too big to count completely
}

// Entry is one item in a folder.
type Entry struct {
	Name     string `json:"name"`
	Kind     string `json:"kind"` // dir, file, link or other
	Size     int64  `json:"size"`
	Modified int64  `json:"modified"`
}

// Listing is the content of a folder.
type Listing struct {
	Entries   []Entry `json:"entries"`
	Truncated bool    `json:"truncated"`
}

// Preview is the start of a file as text.
type Preview struct {
	Size      int64  `json:"size"`
	Content   string `json:"content"`
	Binary    bool   `json:"binary"`
	Truncated bool   `json:"truncated"`
}

// Download is an open plain file; the caller closes it.
type Download struct {
	File *os.File
	Size int64
}

// List returns the volume folders of an app, each with its size. An app that
// has no volumes yet has none, which is not an error.
func (b *Browser) List(ctx context.Context, app string) ([]Volume, error) {
	if err := validate.Name(app); err != nil {
		return nil, err
	}
	base := filepath.Join(b.dir, app)
	if _, err := validate.ResolveUnder(b.dir, base); err != nil {
		return nil, err
	}
	dirs, err := os.ReadDir(base)
	if errors.Is(err, fs.ErrNotExist) {
		return []Volume{}, nil
	}
	if err != nil {
		return nil, err
	}
	out := []Volume{}
	for _, d := range dirs {
		if !d.IsDir() || validate.VolumeName(d.Name()) != nil {
			continue
		}
		v, err := b.size(ctx, app, d.Name())
		if err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

func (b *Browser) size(ctx context.Context, app, name string) (Volume, error) {
	root, err := b.open(app, name)
	if err != nil {
		return Volume{}, err
	}
	defer root.Close()

	ctx, cancel := context.WithTimeout(ctx, sizeTimeLimit)
	defer cancel()

	v := Volume{Name: name}
	seen := 0
	stop := errors.New("stop")
	err = fs.WalkDir(root.FS(), ".", func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil // unreadable parts are skipped
		}
		if seen++; seen > sizeEntryLimit || ctx.Err() != nil {
			v.Partial = true
			return stop
		}
		if d.Type().IsRegular() {
			if info, err := d.Info(); err == nil {
				v.Bytes += info.Size()
				v.Files++
			}
		}
		return nil
	})
	if err != nil && !errors.Is(err, stop) {
		return Volume{}, err
	}
	return v, nil
}

// Dir lists a folder inside a volume. Links are listed but never followed.
func (b *Browser) Dir(app, name, rel string) (Listing, error) {
	root, rel, err := b.at(app, name, rel)
	if err != nil {
		return Listing{}, err
	}
	defer root.Close()

	if info, err := root.Lstat(rel); err != nil {
		return Listing{}, notFound(err)
	} else if !info.IsDir() {
		return Listing{}, &validate.InputError{Msg: "that is not a folder"}
	}
	f, err := root.Open(rel)
	if err != nil {
		return Listing{}, notFound(err)
	}
	defer f.Close()

	des, err := f.ReadDir(MaxEntries + 1)
	if err != nil && !errors.Is(err, io.EOF) {
		return Listing{}, err
	}
	l := Listing{Entries: []Entry{}}
	if len(des) > MaxEntries {
		des, l.Truncated = des[:MaxEntries], true
	}
	for _, d := range des {
		e := Entry{Name: d.Name(), Kind: kind(d.Type())}
		if info, err := d.Info(); err == nil {
			e.Modified = info.ModTime().Unix()
			if e.Kind == "file" {
				e.Size = info.Size()
			}
		}
		l.Entries = append(l.Entries, e)
	}
	sort.Slice(l.Entries, func(i, j int) bool {
		a, c := l.Entries[i], l.Entries[j]
		if (a.Kind == "dir") != (c.Kind == "dir") {
			return a.Kind == "dir"
		}
		return strings.ToLower(a.Name) < strings.ToLower(c.Name)
	})
	return l, nil
}

// Preview returns the start of a plain file as text, or says it is binary.
func (b *Browser) Preview(app, name, rel string) (Preview, error) {
	f, size, err := b.openFile(app, name, rel)
	if err != nil {
		return Preview{}, err
	}
	defer f.Close()

	data, err := io.ReadAll(io.LimitReader(f, PreviewLimit))
	if err != nil {
		return Preview{}, err
	}
	p := Preview{Size: size, Truncated: size > int64(len(data))}
	if bytes.IndexByte(data[:min(binarySniff, len(data))], 0) >= 0 {
		p.Binary = true
		return p, nil
	}
	if p.Truncated {
		// The cut may fall inside a character.
		for i := 0; i < utf8.UTFMax && len(data) > 0 && !utf8.Valid(data); i++ {
			data = data[:len(data)-1]
		}
	}
	if !utf8.Valid(data) {
		p.Binary = true
		return p, nil
	}
	p.Content = string(data)
	return p, nil
}

// Open returns a plain file for download, refusing anything above MaxDownload.
func (b *Browser) Open(app, name, rel string) (Download, error) {
	f, size, err := b.openFile(app, name, rel)
	if err != nil {
		return Download{}, err
	}
	if size > MaxDownload {
		f.Close()
		return Download{}, &validate.InputError{Msg: fmt.Sprintf("that file is larger than %d MB and cannot be fetched here", MaxDownload>>20)}
	}
	return Download{File: f, Size: size}, nil
}

// openFile opens a plain file without ever blocking or following a link at the end.
func (b *Browser) openFile(app, name, rel string) (*os.File, int64, error) {
	root, rel, err := b.at(app, name, rel)
	if err != nil {
		return nil, 0, err
	}
	defer root.Close() // an open file stays usable after its root is closed

	info, err := root.Lstat(rel)
	if err != nil {
		return nil, 0, notFound(err)
	}
	if !info.Mode().IsRegular() {
		return nil, 0, &validate.InputError{Msg: "only plain files can be read"}
	}
	// O_NONBLOCK: a named pipe swapped in after the check must not block the agent.
	f, err := root.OpenFile(rel, os.O_RDONLY|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, 0, notFound(err)
	}
	opened, err := f.Stat()
	if err != nil || !opened.Mode().IsRegular() {
		f.Close()
		return nil, 0, &validate.InputError{Msg: "only plain files can be read"}
	}
	return f, opened.Size(), nil
}

func (b *Browser) at(app, name, rel string) (*os.Root, string, error) {
	rel, err := cleanRel(rel)
	if err != nil {
		return nil, "", err
	}
	root, err := b.open(app, name)
	if err != nil {
		return nil, "", err
	}
	return root, rel, nil
}

func (b *Browser) open(app, name string) (*os.Root, error) {
	if err := validate.Name(app); err != nil {
		return nil, err
	}
	if err := validate.VolumeName(name); err != nil {
		return nil, err
	}
	dir := filepath.Join(b.dir, app, name)
	if _, err := validate.ResolveUnder(b.dir, dir); err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, notFound(err)
	}
	return root, nil
}

// cleanRel turns the path the panel sends into one relative to the volume.
func cleanRel(rel string) (string, error) {
	if rel == "" || rel == "." {
		return ".", nil
	}
	if len(rel) > 1024 || strings.ContainsRune(rel, 0) || !filepath.IsLocal(rel) {
		return "", &validate.InputError{Msg: "invalid path"}
	}
	return filepath.Clean(rel), nil
}

func notFound(err error) error {
	if errors.Is(err, fs.ErrNotExist) {
		return &validate.InputError{Msg: "that does not exist"}
	}
	// Includes a link that points outside the volume: its target is not for us to say.
	if strings.Contains(err.Error(), "escapes") || errors.Is(err, fs.ErrPermission) {
		return &validate.InputError{Msg: "that cannot be opened"}
	}
	return err
}

func kind(t fs.FileMode) string {
	switch {
	case t.IsDir():
		return "dir"
	case t.IsRegular():
		return "file"
	case t&fs.ModeSymlink != 0:
		return "link"
	default:
		return "other"
	}
}
