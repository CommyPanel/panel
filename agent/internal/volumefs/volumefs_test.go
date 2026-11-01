package volumefs

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"commypanel/agent/internal/validate"
)

// fixture builds <data>/volumes/shop/uploads with some files, and a secret
// outside the volumes directory that nothing may ever return.
func fixture(t *testing.T) (*Browser, string, string) {
	t.Helper()
	data := t.TempDir()
	vol := filepath.Join(data, "volumes", "shop", "uploads")
	for _, d := range []string{"photos/2026", "empty"} {
		if err := os.MkdirAll(filepath.Join(vol, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	write := func(rel, content string) {
		if err := os.WriteFile(filepath.Join(vol, rel), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("readme.txt", "hello\nworld\n")
	write("photos/cat.jpg", "\xff\xd8\xff\x00binary")
	write("photos/2026/new.txt", "deep")

	secret := filepath.Join(data, "secret.txt")
	if err := os.WriteFile(secret, []byte("TOP SECRET"), 0o600); err != nil {
		t.Fatal(err)
	}
	return New(data), vol, secret
}

func isInput(err error) bool {
	var ie *validate.InputError
	return errors.As(err, &ie)
}

func TestListSizesEachVolume(t *testing.T) {
	b, vol, _ := fixture(t)
	other := filepath.Join(filepath.Dir(vol), "cache")
	os.MkdirAll(other, 0o755)
	os.WriteFile(filepath.Join(other, "a"), make([]byte, 1000), 0o644)

	got, err := b.List(context.Background(), "shop")
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || got[0].Name != "cache" || got[0].Bytes != 1000 || got[0].Files != 1 {
		t.Fatalf("got %+v", got)
	}
	want := int64(len("hello\nworld\n") + len("\xff\xd8\xff\x00binary") + len("deep"))
	if got[1].Name != "uploads" || got[1].Bytes != want || got[1].Files != 3 || got[1].Partial {
		t.Errorf("uploads = %+v, want %d bytes in 3 files", got[1], want)
	}

	none, err := b.List(context.Background(), "blog")
	if err != nil || len(none) != 0 {
		t.Errorf("an app without volumes: %v %v", none, err)
	}
}

func TestListSkipsLooseFilesAndBadNames(t *testing.T) {
	b, vol, _ := fixture(t)
	base := filepath.Dir(vol)
	os.WriteFile(filepath.Join(base, "stray-file"), []byte("x"), 0o644)
	os.MkdirAll(filepath.Join(base, "Bad Name"), 0o755)

	got, _ := b.List(context.Background(), "shop")
	if len(got) != 1 || got[0].Name != "uploads" {
		t.Errorf("got %+v", got)
	}
}

func TestSizeDoesNotFollowLinksOutOfTheVolume(t *testing.T) {
	b, vol, _ := fixture(t)
	big := t.TempDir()
	os.WriteFile(filepath.Join(big, "huge"), make([]byte, 5_000_000), 0o644)
	os.Symlink(big, filepath.Join(vol, "link-to-elsewhere"))

	got, _ := b.List(context.Background(), "shop")
	if got[0].Bytes > 1000 {
		t.Errorf("counted %d bytes, a link was followed", got[0].Bytes)
	}
}

func TestDirListsFoldersFirstAndMarksLinks(t *testing.T) {
	b, vol, secret := fixture(t)
	os.Symlink(secret, filepath.Join(vol, "a-link"))

	l, err := b.Dir("shop", "uploads", "")
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, e := range l.Entries {
		names = append(names, e.Kind+":"+e.Name)
	}
	if strings.Join(names, " ") != "dir:empty dir:photos link:a-link file:readme.txt" {
		t.Errorf("order = %v", names)
	}
	for _, e := range l.Entries {
		if e.Name == "readme.txt" && e.Size != 12 {
			t.Errorf("readme size = %d", e.Size)
		}
		if e.Kind == "link" && e.Size != 0 {
			t.Errorf("a link must not report its target's size: %+v", e)
		}
	}

	sub, err := b.Dir("shop", "uploads", "photos/2026")
	if err != nil || len(sub.Entries) != 1 || sub.Entries[0].Name != "new.txt" {
		t.Errorf("subfolder: %+v %v", sub, err)
	}
}

func TestDirTruncatesHugeFolders(t *testing.T) {
	b, vol, _ := fixture(t)
	many := filepath.Join(vol, "many")
	os.Mkdir(many, 0o755)
	for i := 0; i < MaxEntries+25; i++ {
		os.WriteFile(filepath.Join(many, "f"+strconv.Itoa(i)), nil, 0o644)
	}
	l, err := b.Dir("shop", "uploads", "many")
	if err != nil {
		t.Fatal(err)
	}
	if len(l.Entries) != MaxEntries || !l.Truncated {
		t.Errorf("got %d entries, truncated=%v", len(l.Entries), l.Truncated)
	}
}

func TestPreview(t *testing.T) {
	b, vol, _ := fixture(t)

	p, err := b.Preview("shop", "uploads", "readme.txt")
	if err != nil || p.Content != "hello\nworld\n" || p.Binary || p.Truncated || p.Size != 12 {
		t.Errorf("text: %+v %v", p, err)
	}

	p, err = b.Preview("shop", "uploads", "photos/cat.jpg")
	if err != nil || !p.Binary || p.Content != "" {
		t.Errorf("binary: %+v %v", p, err)
	}

	big := strings.Repeat("é", PreviewLimit) // 2 bytes each: the cut can fall inside a character
	os.WriteFile(filepath.Join(vol, "big.txt"), []byte(big), 0o644)
	p, err = b.Preview("shop", "uploads", "big.txt")
	if err != nil || !p.Truncated || p.Binary || len(p.Content) > PreviewLimit || !strings.HasPrefix(big, p.Content) {
		t.Errorf("large: truncated=%v binary=%v len=%d err=%v", p.Truncated, p.Binary, len(p.Content), err)
	}

	os.WriteFile(filepath.Join(vol, "latin1.txt"), []byte("caf\xe9"), 0o644)
	if p, _ := b.Preview("shop", "uploads", "latin1.txt"); !p.Binary {
		t.Error("invalid UTF-8 should be treated as binary, not shown garbled")
	}
}

func TestOpenRefusesWhatIsTooLarge(t *testing.T) {
	b, vol, _ := fixture(t)
	f, _ := os.Create(filepath.Join(vol, "sparse"))
	f.Truncate(MaxDownload + 1)
	f.Close()

	if _, err := b.Open("shop", "uploads", "sparse"); !isInput(err) {
		t.Errorf("want an input error, got %v", err)
	}
	d, err := b.Open("shop", "uploads", "readme.txt")
	if err != nil || d.Size != 12 {
		t.Fatalf("open: %v", err)
	}
	d.File.Close()
}

// Everything below is what a hostile app could leave in its own volume.

func TestLinksToTheHostAreNeverFollowed(t *testing.T) {
	b, vol, secret := fixture(t)
	os.Symlink(secret, filepath.Join(vol, "abs-link"))
	os.Symlink("../../../secret.txt", filepath.Join(vol, "rel-link"))
	os.Symlink(filepath.Dir(secret), filepath.Join(vol, "dir-link"))
	os.Symlink("/etc", filepath.Join(vol, "etc"))
	os.Symlink(filepath.Dir(secret), filepath.Join(vol, "photos", "up"))

	for _, rel := range []string{"abs-link", "rel-link", "dir-link/secret.txt", "etc/passwd", "photos/up/secret.txt", "dir-link"} {
		if p, err := b.Preview("shop", "uploads", rel); err == nil {
			t.Errorf("%s: preview returned %+v", rel, p)
		}
		if d, err := b.Open("shop", "uploads", rel); err == nil {
			d.File.Close()
			t.Errorf("%s: open succeeded", rel)
		}
	}
	for _, rel := range []string{"dir-link", "etc", "photos/up", "dir-link/x"} {
		if l, err := b.Dir("shop", "uploads", rel); err == nil {
			t.Errorf("%s: listing returned %+v", rel, l)
		}
	}
}

func TestTraversalIsRefused(t *testing.T) {
	b, _, _ := fixture(t)

	for _, rel := range []string{"..", "../secret.txt", "../../../../etc/passwd", "/etc/passwd", "photos/../../secret.txt", "a\x00b", strings.Repeat("a/", 600)} {
		if _, err := b.Preview("shop", "uploads", rel); err == nil {
			t.Errorf("preview %q must fail", rel)
		}
		if _, err := b.Dir("shop", "uploads", rel); err == nil {
			t.Errorf("dir %q must fail", rel)
		}
	}
	for _, c := range [][2]string{{"../shop", "uploads"}, {"shop", "../uploads"}, {"shop", "up/loads"}, {"Shop", "uploads"}, {"shop", ""}, {"", "uploads"}} {
		if _, err := b.Dir(c[0], c[1], ""); err == nil {
			t.Errorf("volume %q/%q must be refused", c[0], c[1])
		}
	}
}

func TestAVolumeThatIsItselfALinkIsRefused(t *testing.T) {
	b, vol, _ := fixture(t)
	elsewhere := t.TempDir()
	os.WriteFile(filepath.Join(elsewhere, "x"), []byte("outside"), 0o644)
	linked := filepath.Join(filepath.Dir(vol), "linked")
	os.Symlink(elsewhere, linked)

	if _, err := b.Dir("shop", "linked", ""); err == nil {
		t.Error("a volume that links out of the volumes directory must be refused")
	}
}

func TestOnlyPlainFilesAreRead(t *testing.T) {
	b, vol, _ := fixture(t)
	fifo := filepath.Join(vol, "pipe")
	if err := syscall.Mkfifo(fifo, 0o644); err != nil {
		t.Skip("cannot create a named pipe here:", err)
	}

	done := make(chan error, 2)
	go func() { _, err := b.Preview("shop", "uploads", "pipe"); done <- err }()
	go func() {
		d, err := b.Open("shop", "uploads", "pipe")
		if err == nil {
			d.File.Close()
		}
		done <- err
	}()
	for i := 0; i < 2; i++ {
		select {
		case err := <-done:
			if !isInput(err) {
				t.Errorf("a named pipe must be refused, got %v", err)
			}
		case <-time.After(3 * time.Second):
			t.Fatal("reading a named pipe blocked the agent")
		}
	}

	if _, err := b.Preview("shop", "uploads", "photos"); !isInput(err) {
		t.Errorf("a folder is not a file: %v", err)
	}
	if _, err := b.Dir("shop", "uploads", "readme.txt"); !isInput(err) {
		t.Errorf("a file is not a folder: %v", err)
	}
	if l, err := b.Dir("shop", "uploads", ""); err != nil {
		t.Errorf("a pipe in the folder must not break the listing: %v", err)
	} else {
		for _, e := range l.Entries {
			if e.Name == "pipe" && e.Kind != "other" {
				t.Errorf("pipe listed as %q", e.Kind)
			}
		}
	}
}

func TestMissingThingsAreInputErrors(t *testing.T) {
	b, _, _ := fixture(t)
	if _, err := b.Preview("shop", "uploads", "nope.txt"); !isInput(err) {
		t.Errorf("missing file: %v", err)
	}
	if _, err := b.Dir("shop", "nothing", ""); !isInput(err) {
		t.Errorf("missing volume: %v", err)
	}
}

func FuzzCleanRel(f *testing.F) {
	for _, s := range []string{"", ".", "a", "a/b", "../x", "/abs", "a/../../b", "a\x00", "..."} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, s string) {
		got, err := cleanRel(s)
		if err != nil {
			return
		}
		if filepath.IsAbs(got) || got == ".." || strings.HasPrefix(got, "../") || strings.ContainsRune(got, 0) {
			t.Fatalf("cleanRel(%q) = %q escapes the volume", s, got)
		}
	})
}
