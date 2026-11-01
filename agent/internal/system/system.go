// Package system reports basic host facts for the panel's status screen.
package system

import (
	"os/exec"
	"syscall"
)

// Status is what the panel needs to know about the host. Whether Docker
// answers is asked from the Docker manager by the server.
type Status struct {
	NginxAvailable bool   `json:"nginx_available"`
	DiskFreeBytes  uint64 `json:"disk_free_bytes"`
}

// Collect gathers the status. dataDir is where free disk space is measured.
func Collect(dataDir string) Status {
	_, nginxErr := exec.LookPath("nginx")

	return Status{
		NginxAvailable: nginxErr == nil,
		DiskFreeBytes:  diskFree(dataDir),
	}
}

func diskFree(dir string) uint64 {
	for _, path := range []string{dir, "/"} {
		var st syscall.Statfs_t
		if err := syscall.Statfs(path, &st); err == nil {
			return uint64(st.Bavail) * uint64(st.Bsize)
		}
	}
	return 0
}

// diskUsage returns the total and free bytes of the filesystem holding dir.
func diskUsage(dir string) (total, free uint64) {
	for _, path := range []string{dir, "/"} {
		var st syscall.Statfs_t
		if err := syscall.Statfs(path, &st); err == nil {
			return uint64(st.Blocks) * uint64(st.Bsize), uint64(st.Bavail) * uint64(st.Bsize)
		}
	}
	return 0, 0
}
