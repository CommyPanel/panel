package terminal

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// maskedFrame encodes a client frame.
func maskedFrame(fin bool, opcode byte, payload []byte) []byte {
	b0 := opcode
	if fin {
		b0 |= 0x80
	}
	out := []byte{b0}
	switch n := len(payload); {
	case n < 126:
		out = append(out, 0x80|byte(n))
	case n <= 0xFFFF:
		out = append(out, 0x80|126, byte(n>>8), byte(n))
	default:
		out = append(out, 0x80|127)
		out = binary.BigEndian.AppendUint64(out, uint64(n))
	}
	mask := [4]byte{0x12, 0x34, 0x56, 0x78}
	out = append(out, mask[:]...)
	for i, c := range payload {
		out = append(out, c^mask[i%4])
	}
	return out
}

type peer struct {
	t  *testing.T
	c  net.Conn
	br *bufio.Reader
}

func (p *peer) send(b []byte) {
	p.t.Helper()
	if _, err := p.c.Write(b); err != nil {
		p.t.Fatal(err)
	}
}

// read returns the next server frame (opcode, payload).
func (p *peer) read() (byte, []byte) {
	p.t.Helper()
	_ = p.c.SetReadDeadline(time.Now().Add(3 * time.Second))
	var head [2]byte
	if _, err := io.ReadFull(p.br, head[:]); err != nil {
		p.t.Fatalf("reading a frame: %v", err)
	}
	n := int(head[1] & 0x7F)
	switch n {
	case 126:
		var b [2]byte
		io.ReadFull(p.br, b[:])
		n = int(binary.BigEndian.Uint16(b[:]))
	case 127:
		var b [8]byte
		io.ReadFull(p.br, b[:])
		n = int(binary.BigEndian.Uint64(b[:]))
	}
	payload := make([]byte, n)
	io.ReadFull(p.br, payload)
	return head[0] & 0x0F, payload
}

// serve runs an echo server: it replies to each message with the same kind of message.
func serve(t *testing.T, allow func(string) bool) (addr string, results chan error) {
	t.Helper()
	results = make(chan error, 4)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := Upgrade(w, r, allow)
		if err != nil {
			return
		}
		for {
			text, data, err := c.ReadMessage()
			if err != nil {
				results <- err
				return
			}
			if text {
				c.WriteText(data)
			} else {
				c.WriteBinary(data)
			}
		}
	}))
	t.Cleanup(srv.Close)
	return strings.TrimPrefix(srv.URL, "http://"), results
}

func dial(t *testing.T, addr string, headers map[string]string) (*peer, string) {
	t.Helper()
	return dialRaw(t, addr, "/terminal", headers)
}

// dialPath connects to a path as a browser at the given origin would.
func dialPath(t *testing.T, addr, path, origin string) (*peer, string) {
	t.Helper()
	return dialRaw(t, addr, path, map[string]string{"Origin": origin})
}

func dialRaw(t *testing.T, addr, path string, headers map[string]string) (*peer, string) {
	t.Helper()
	c, err := net.Dial("tcp", addr)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	h := map[string]string{
		"Host": addr, "Connection": "Upgrade", "Upgrade": "websocket", "Sec-WebSocket-Version": "13",
		"Sec-WebSocket-Key": "dGhlIHNhbXBsZSBub25jZQ==", "Origin": "https://panel.example.com",
	}
	for k, v := range headers {
		if v == "" {
			delete(h, k)
		} else {
			h[k] = v
		}
	}
	var req bytes.Buffer
	req.WriteString("GET " + path + " HTTP/1.1\r\n")
	for k, v := range h {
		req.WriteString(k + ": " + v + "\r\n")
	}
	req.WriteString("\r\n")
	c.Write(req.Bytes())
	br := bufio.NewReader(c)
	status, _ := br.ReadString('\n')
	var accept string
	for {
		line, err := br.ReadString('\n')
		if err != nil || line == "\r\n" {
			break
		}
		if strings.HasPrefix(line, "Sec-WebSocket-Accept:") {
			accept = strings.TrimSpace(strings.TrimPrefix(line, "Sec-WebSocket-Accept:"))
		}
	}
	_ = accept
	return &peer{t: t, c: c, br: br}, strings.TrimSpace(status) + "|" + accept
}

var allowPanel = func(origin string) bool { return origin == "https://panel.example.com" }

func TestHandshakeUsesTheKeyFromTheRFC(t *testing.T) {
	addr, _ := serve(t, allowPanel)
	_, got := dial(t, addr, nil)
	if got != "HTTP/1.1 101 Switching Protocols|s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" {
		t.Errorf("got %q", got)
	}
}

func TestHandshakeRefusals(t *testing.T) {
	addr, _ := serve(t, allowPanel)
	for name, tc := range map[string]struct {
		headers map[string]string
		want    string
	}{
		"another origin":         {map[string]string{"Origin": "https://evil.example.com"}, "403"},
		"no origin":              {map[string]string{"Origin": ""}, "403"},
		"no upgrade":             {map[string]string{"Upgrade": ""}, "400"},
		"no connection":          {map[string]string{"Connection": "close"}, "400"},
		"old version":            {map[string]string{"Sec-WebSocket-Version": "8"}, "426"},
		"missing key":            {map[string]string{"Sec-WebSocket-Key": ""}, "400"},
		"key of the wrong size":  {map[string]string{"Sec-WebSocket-Key": base64.StdEncoding.EncodeToString([]byte("short"))}, "400"},
		"key that is not base64": {map[string]string{"Sec-WebSocket-Key": "!!!"}, "400"},
	} {
		_, got := dial(t, addr, tc.headers)
		if !strings.Contains(got, " "+tc.want+" ") {
			t.Errorf("%s: %s, want %s", name, got, tc.want)
		}
	}
}

func TestEchoOfTextAndBinaryWithLargePayloads(t *testing.T) {
	addr, _ := serve(t, allowPanel)
	p, _ := dial(t, addr, nil)

	p.send(maskedFrame(true, opText, []byte(`{"type":"resize"}`)))
	if op, data := p.read(); op != opText || string(data) != `{"type":"resize"}` {
		t.Errorf("text: %d %q", op, data)
	}
	for _, size := range []int{0, 125, 126, 5000, 65535} {
		payload := bytes.Repeat([]byte{0xAB}, size)
		p.send(maskedFrame(true, opBinary, payload))
		if op, data := p.read(); op != opBinary || !bytes.Equal(data, payload) {
			t.Errorf("binary of %d bytes: op %d, got %d bytes", size, op, len(data))
		}
	}
}

func TestPingIsAnsweredAndFragmentsAreJoined(t *testing.T) {
	addr, _ := serve(t, allowPanel)
	p, _ := dial(t, addr, nil)

	p.send(maskedFrame(true, opPing, []byte("hi")))
	if op, data := p.read(); op != opPong || string(data) != "hi" {
		t.Errorf("pong: %d %q", op, data)
	}

	// A ping between the fragments of a message is allowed.
	p.send(maskedFrame(false, opText, []byte("hel")))
	p.send(maskedFrame(true, opPing, nil))
	p.send(maskedFrame(false, opContinuation, []byte("lo ")))
	p.send(maskedFrame(true, opContinuation, []byte("world")))
	if op, data := p.read(); op != opPong || len(data) != 0 {
		t.Errorf("pong between fragments: %d %q", op, data)
	}
	if op, data := p.read(); op != opText || string(data) != "hello world" {
		t.Errorf("joined: %d %q", op, data)
	}
}

func TestProtocolViolationsCloseTheConnection(t *testing.T) {
	tooBig := bytes.Repeat([]byte("x"), MaxMessage+1)
	bad := map[string][]byte{
		"unmasked frame":              {0x81, 0x02, 'h', 'i'},
		"reserved bit":                append([]byte{0xC1, 0x80}, 0, 0, 0, 0),
		"unknown opcode":              maskedFrame(true, 0x3, nil),
		"fragmented ping":             maskedFrame(false, opPing, nil),
		"huge ping":                   maskedFrame(true, opPing, bytes.Repeat([]byte("x"), 126)),
		"continuation from nowhere":   maskedFrame(true, opContinuation, []byte("x")),
		"a message that is too large": maskedFrame(true, opBinary, tooBig),
		"text that is not UTF-8":      maskedFrame(true, opText, []byte{0xff, 0xfe}),
	}
	for name, raw := range bad {
		addr, results := serve(t, allowPanel)
		p, _ := dial(t, addr, nil)
		p.send(raw)

		select {
		case err := <-results:
			if err == nil {
				t.Errorf("%s: no error", name)
			}
		case <-time.After(3 * time.Second):
			t.Errorf("%s: the server kept reading", name)
		}
	}
}

func TestInterleavedMessagesAreRefused(t *testing.T) {
	addr, results := serve(t, allowPanel)
	p, _ := dial(t, addr, nil)
	p.send(maskedFrame(false, opText, []byte("a")))
	p.send(maskedFrame(true, opText, []byte("b")))
	if err := <-results; !errors.Is(err, ErrProtocol) {
		t.Errorf("got %v", err)
	}
}

func TestCloseHandshake(t *testing.T) {
	addr, results := serve(t, allowPanel)
	p, _ := dial(t, addr, nil)

	p.send(maskedFrame(true, opClose, binary.BigEndian.AppendUint16(nil, CloseGoingAway)))
	if op, data := p.read(); op != opClose || binary.BigEndian.Uint16(data) != CloseNormal {
		t.Errorf("close answer: %d %v", op, data)
	}
	var ce *CloseError
	if err := <-results; !errors.As(err, &ce) || ce.Code != CloseGoingAway {
		t.Errorf("got %v", err)
	}
}

func TestReadFrameBounds(t *testing.T) {
	// 64-bit length with the top bit set, and a length above the limit: refused before allocating.
	for name, raw := range map[string][]byte{
		"huge 64-bit length": append([]byte{0x82, 0x80 | 127, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF}, 1, 2, 3, 4),
		"above the limit":    append([]byte{0x82, 0x80 | 127, 0, 0, 0, 0, 0, 0x10, 0, 0}, 1, 2, 3, 4),
	} {
		if _, err := readFrame(bytes.NewReader(raw), MaxMessage); !errors.Is(err, ErrProtocol) {
			t.Errorf("%s: %v", name, err)
		}
	}
	if _, err := readFrame(bytes.NewReader(maskedFrame(true, opText, []byte("ab"))[:3]), MaxMessage); err == nil {
		t.Error("a truncated frame must fail")
	}
}

func FuzzReadFrame(f *testing.F) {
	f.Add(maskedFrame(true, opText, []byte("hello")))
	f.Add(maskedFrame(false, opBinary, bytes.Repeat([]byte("x"), 200)))
	f.Add(maskedFrame(true, opPing, nil))
	f.Add([]byte{0x81, 0x7F, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF})
	f.Add([]byte{0x00})
	f.Fuzz(func(t *testing.T, raw []byte) {
		fr, err := readFrame(bytes.NewReader(raw), 4096)
		if err != nil {
			return
		}
		if len(fr.payload) > 4096 {
			t.Fatalf("payload of %d bytes beyond the limit", len(fr.payload))
		}
		if fr.opcode >= opClose && (!fr.fin || len(fr.payload) > maxControl) {
			t.Fatal("an invalid control frame was accepted")
		}
	})
}

func TestAppendFrameRoundTrip(t *testing.T) {
	for _, n := range []int{0, 1, 125, 126, 127, 65535, 65536, 100000} {
		p := bytes.Repeat([]byte{7}, n)
		raw := appendFrame(nil, opBinary, p)
		// The parser needs masked frames; check the server frame header by hand.
		if raw[0] != 0x82 || raw[1]&0x80 != 0 {
			t.Errorf("n=%d header %x", n, raw[:2])
		}
		if !bytes.HasSuffix(raw, p) {
			t.Errorf("n=%d payload not at the end", n)
		}
	}
}
