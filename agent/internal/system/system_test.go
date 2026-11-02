package system

import "testing"

func TestCollectReportsTheDiskOfTheDataDirectory(t *testing.T) {
	st := Collect(t.TempDir())

	if st.DiskTotalBytes == 0 || st.DiskFreeBytes > st.DiskTotalBytes {
		t.Errorf("disk = %d free of %d", st.DiskFreeBytes, st.DiskTotalBytes)
	}
}
