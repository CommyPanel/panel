package validate

import "testing"

func TestVolumeArchiveNames(t *testing.T) {
	for s, ok := range map[string]bool{
		"uploads-20261103-040000.tar.gz":    true,
		"a-20261103-040000.tar.gz":          true,
		"../uploads-20261103-040000.tar.gz": false,
		"a/b-20261103-040000.tar.gz":        false,
		"uploads-20261103-040000.tar":       false,
		"Uploads-20261103-040000.tar.gz":    false,
		"-x-20261103-040000.tar.gz":         false,
		"uploads-2026-040000.tar.gz":        false,
		"":                                  false,
	} {
		if got := VolumeArchive(s) == nil; got != ok {
			t.Errorf("%q: %v, want %v", s, got, ok)
		}
	}
}
