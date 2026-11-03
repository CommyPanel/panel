package docker

import (
	"context"
	"fmt"
	"net/url"
	"regexp"
	"strings"

	"commypanel/agent/internal/validate"
)

// ToolLabel marks the containers of database tool sessions, so that the agent
// can remove what a crash left behind.
const ToolLabel = "com.commypanel.dbtool"

// The only database tools, with the image each one runs from. The images are
// chosen here, never by a request.
const (
	PhpMyAdminImage = "phpmyadmin:5.2"
	AdminerImage    = "adminer:5"
)

// ToolSpec is a database tool (phpMyAdmin for MySQL and MariaDB, Adminer for
// PostgreSQL) started for one database, logged in as that database's own user.
type ToolSpec struct {
	Name     string // dbtool-<random>
	Tool     string // "phpmyadmin" or "adminer"
	Engine   string
	Server   string // the database server's slug: its container is db-<slug>
	User     string
	Password string
	Port     int    // published on 127.0.0.1 only
	BaseURL  string // https://host:port/s/<session>/ , where the browser sees it
}

var toolBaseRe = regexp.MustCompile(`^https://(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)(:[0-9]{1,5})?/s/[A-Za-z0-9_-]{16,64}/$`)

// ToolFor says which tool belongs to a database engine, or fails.
func ToolFor(engine string) (tool, image string, containerPort int, err error) {
	if err = validate.DatabaseEngine(engine); err != nil {
		return "", "", 0, err
	}
	if engine == "postgres" {
		return "adminer", AdminerImage, 8080, nil
	}
	return "phpmyadmin", PhpMyAdminImage, 80, nil
}

func (t ToolSpec) validate() error {
	if err := validate.Name(t.Name); err != nil {
		return err
	}
	if !strings.HasPrefix(t.Name, "dbtool-") {
		return &validate.InputError{Msg: "a database tool container is named dbtool-..."}
	}
	tool, image, _, err := ToolFor(t.Engine)
	if err != nil {
		return err
	}
	if t.Tool != tool {
		return &validate.InputError{Msg: fmt.Sprintf("%s is not the tool for %s", t.Tool, t.Engine)}
	}
	if err := validate.ToolImage(image); err != nil {
		return err
	}
	if err := validate.Name(t.Server); err != nil {
		return err
	}
	if err := checkDatabaseUser(t.User); err != nil {
		return err
	}
	if err := validate.DatabasePassword(t.Password); err != nil {
		return err
	}
	if err := validate.Port(t.Port); err != nil {
		return err
	}
	if !toolBaseRe.MatchString(t.BaseURL) {
		return &validate.InputError{Msg: "invalid tool address"}
	}
	return nil
}

// ToolNetwork is the network of a database server, the only one a tool joins.
func ToolNetwork(server string) string { return "commypanel-db-" + server }

// RunTool starts a database tool next to its database server: on that server's
// own network and nowhere else, without privileges, with small limits, published
// on the loopback only and never restarted by Docker. It is logged in, from outside, as the
// one database user it was given; the password is not in its environment.
func (m *Manager) RunTool(ctx context.Context, t ToolSpec) error {
	if err := t.validate(); err != nil {
		return err
	}
	_, image, containerPort, _ := ToolFor(t.Engine)

	// The container never holds the password. Both tools show their login form to anyone who
	// reaches the container's port, and the agent logs in from outside, for the browser it lets
	// in (see package dbtool). A process on this machine that finds the port therefore meets
	// a login form, not a logged-in session.
	env := map[string]string{}
	switch t.Tool {
	case "phpmyadmin":
		env["PMA_HOST"] = "db-" + t.Server
		env["PMA_PORT"] = "3306"
		env["PMA_ABSOLUTE_URI"] = t.BaseURL
		env["UPLOAD_LIMIT"] = "128M"
		env["HIDE_PHP_VERSION"] = "true"
	case "adminer":
		env["ADMINER_DEFAULT_SERVER"] = "db-" + t.Server
	}

	return m.start(ctx, ContainerSpec{
		Name:          t.Name,
		Image:         image,
		HostPort:      t.Port,
		ContainerPort: containerPort,
		Env:           env,
		MemoryMB:      256,
		CPUs:          0.5,
		Network:       ToolNetwork(t.Server),
		extraLabels:   map[string]string{ToolLabel: "true"},
		noRestart:     true,
	})
}

// ToolContainers lists the database tool containers that exist, running or not.
func (m *Manager) ToolContainers(ctx context.Context) ([]string, error) {
	if m.cfg.DryRun {
		return nil, nil
	}
	var raw []struct {
		Names []string `json:"Names"`
	}
	filters := url.Values{"all": {"true"}, "filters": {`{"label":["` + ToolLabel + `=true"]}`}}
	if err := m.engine.doJSON(ctx, "GET", "/containers/json", filters, nil, &raw); err != nil {
		return nil, err
	}
	var names []string
	for _, c := range raw {
		for _, n := range c.Names {
			names = append(names, strings.TrimPrefix(n, "/"))
		}
	}
	return names, nil
}
