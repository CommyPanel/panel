package terminal

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"net/url"
	"sync"
	"time"

	"commypanel/agent/internal/validate"
)

// TicketTTL is how long a ticket can wait for its browser to connect.
const TicketTTL = 30 * time.Second

// Ticket is what a browser may do with one connection: open a shell in one
// container, from one origin.
type Ticket struct {
	Container string
	Origin    string
}

type entry struct {
	Ticket
	expires time.Time
}

// Tickets are the only way into the terminal socket. The panel registers one
// after it has checked permission and password; the browser presents it once.
// They live in memory only, are single use, expire quickly, and only their
// hashes are kept, so a memory dump of idle tickets reveals nothing usable.
type Tickets struct {
	mu      sync.Mutex
	pending map[[32]byte]entry
	ttl     time.Duration
	max     int
	now     func() time.Time
}

// NewTickets returns a store whose tickets are valid for ttl and which holds
// at most max unused tickets at a time.
func NewTickets(ttl time.Duration, max int) *Tickets {
	return &Tickets{pending: map[[32]byte]entry{}, ttl: ttl, max: max, now: time.Now}
}

// ErrTooManyTickets means too many unused tickets are waiting.
var ErrTooManyTickets = errors.New("too many terminal tickets are waiting")

// Issue registers a ticket for a container and an origin such as
// https://panel.example.com, and returns the secret to give the browser.
func (t *Tickets) Issue(container, origin string) (string, error) {
	if err := validate.Name(container); err != nil {
		return "", err
	}
	if err := checkOrigin(origin); err != nil {
		return "", err
	}
	var raw [32]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return "", err
	}
	token := base64.RawURLEncoding.EncodeToString(raw[:])

	t.mu.Lock()
	defer t.mu.Unlock()
	t.prune()
	if len(t.pending) >= t.max {
		return "", ErrTooManyTickets
	}
	t.pending[sha256.Sum256([]byte(token))] = entry{Ticket{container, origin}, t.now().Add(t.ttl)}
	return token, nil
}

// Redeem consumes a ticket. It works once: a second attempt, an expired ticket
// and a made-up one are all the same "no".
func (t *Tickets) Redeem(token string) (Ticket, bool) {
	if len(token) != 43 { // 32 bytes, unpadded base64
		return Ticket{}, false
	}
	key := sha256.Sum256([]byte(token))

	t.mu.Lock()
	defer t.mu.Unlock()
	e, ok := t.pending[key]
	if !ok {
		return Ticket{}, false
	}
	delete(t.pending, key)
	if !t.now().Before(e.expires) {
		return Ticket{}, false
	}
	return e.Ticket, true
}

// Pending is the number of unused tickets.
func (t *Tickets) Pending() int {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.prune()
	return len(t.pending)
}

func (t *Tickets) prune() {
	now := t.now()
	for k, e := range t.pending {
		if !now.Before(e.expires) {
			delete(t.pending, k)
		}
	}
}

// checkOrigin accepts only a bare origin: scheme and host, no path.
func checkOrigin(origin string) error {
	u, err := url.Parse(origin)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" || u.User != nil ||
		u.Path != "" || u.RawQuery != "" || u.Fragment != "" || len(origin) > 255 || u.String() != origin {
		return &validate.InputError{Msg: "invalid origin"}
	}
	return nil
}
