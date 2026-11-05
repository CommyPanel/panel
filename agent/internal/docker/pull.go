package docker

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/url"
	"strings"

	"commypanel/agent/internal/validate"
)

// PullImage downloads an official database image from Docker Hub. Only
// mariadb, mysql and postgres with a plain version tag are accepted, and an
// image that is already there is not downloaded again.
func (m *Manager) PullImage(ctx context.Context, image string) error {
	if err := validate.DatabaseImage(image); err != nil {
		return err
	}
	return m.pull(ctx, image)
}

// PullToolImage is PullImage for the database tools (phpMyAdmin, pgAdmin). It is
// not offered to the panel directly; the database tool sessions call it with an
// image the agent itself chose.
func (m *Manager) PullToolImage(ctx context.Context, image string) error {
	if err := validate.ToolImage(image); err != nil {
		return err
	}
	return m.pull(ctx, image)
}

func (m *Manager) pull(ctx context.Context, image string) error {
	if m.cfg.DryRun {
		slog.Info("dry-run: would pull image", "image", image)
		return nil
	}
	repo, tag, _ := strings.Cut(image, ":")

	err := m.engine.doJSON(ctx, "GET", "/images/"+image+"/json", nil, nil, nil)
	if err == nil {
		return nil
	}
	if !isStatus(err, 404) {
		return err
	}

	resp, err := m.engine.do(ctx, "POST", "/images/create", url.Values{"fromImage": {repo}, "tag": {tag}}, nil, "")
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	// Docker streams progress as one JSON object per line; a failure arrives as an "error" in it.
	dec := json.NewDecoder(io.LimitReader(resp.Body, 64<<20))
	for {
		var line struct {
			Error string `json:"error"`
		}
		if err := dec.Decode(&line); err != nil {
			if errors.Is(err, io.EOF) {
				break
			}
			return fmt.Errorf("pull %s: %w", image, err)
		}
		if line.Error != "" {
			return &validate.InputError{Msg: fmt.Sprintf("could not download %s: %s", image, line.Error)}
		}
	}
	slog.Info("image pulled", "image", image)
	return nil
}
