// Package terminal serves interactive shells in managed containers over
// WebSocket. This file is the WebSocket part (RFC 6455), implemented with the
// standard library only: the agent's single third-party dependency is the ACME
// client, and a terminal needs only a small, strict subset of the protocol.
//
// The parser is strict because it reads bytes from a browser connection on a
// process that runs as root: client frames must be masked, reserved bits and
// unknown opcodes are refused, control frames are small and unfragmented,
// and every length is bounded before anything is allocated.
package terminal

import (
	"bufio"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
	"unicode/utf8"
)

const (
	opContinuation = 0x0
	opText         = 0x1
	opBinary       = 0x2
	opClose        = 0x8
	opPing         = 0x9
	opPong         = 0xA

	// MaxMessage bounds one (possibly fragmented) message from the browser.
	// Keystrokes and resize messages are tiny; a paste is the largest thing.
	MaxMessage = 64 << 10

	maxControl    = 125
	handshakeGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
)

// Close codes (RFC 6455 section 7.4).
const (
	CloseNormal      = 1000
	CloseGoingAway   = 1001
	CloseProtocol    = 1002
	CloseUnsupported = 1003
	CloseInvalidData = 1007
	ClosePolicy      = 1008
	CloseTooBig      = 1009
	CloseInternal    = 1011
)

// ErrProtocol is returned for frames that break the protocol.
var ErrProtocol = errors.New("websocket protocol error")

// CloseError is a close frame received from the peer.
type CloseError struct{ Code int }

func (e *CloseError) Error() string { return fmt.Sprintf("websocket closed (%d)", e.Code) }

type frame struct {
	fin     bool
	opcode  byte
	payload []byte
}

// readFrame reads one frame sent by a client. maxPayload bounds the payload.
func readFrame(r io.Reader, maxPayload int) (frame, error) {
	var head [2]byte
	if _, err := io.ReadFull(r, head[:]); err != nil {
		return frame{}, err
	}
	f := frame{fin: head[0]&0x80 != 0, opcode: head[0] & 0x0F}
	if head[0]&0x70 != 0 {
		return frame{}, fmt.Errorf("%w: reserved bits set", ErrProtocol)
	}
	switch f.opcode {
	case opContinuation, opText, opBinary, opClose, opPing, opPong:
	default:
		return frame{}, fmt.Errorf("%w: opcode %d", ErrProtocol, f.opcode)
	}
	if head[1]&0x80 == 0 {
		return frame{}, fmt.Errorf("%w: client frame is not masked", ErrProtocol)
	}
	length := uint64(head[1] & 0x7F)
	switch length {
	case 126:
		var b [2]byte
		if _, err := io.ReadFull(r, b[:]); err != nil {
			return frame{}, err
		}
		length = uint64(binary.BigEndian.Uint16(b[:]))
	case 127:
		var b [8]byte
		if _, err := io.ReadFull(r, b[:]); err != nil {
			return frame{}, err
		}
		length = binary.BigEndian.Uint64(b[:])
	}
	isControl := f.opcode >= opClose
	if isControl && (!f.fin || length > maxControl) {
		return frame{}, fmt.Errorf("%w: bad control frame", ErrProtocol)
	}
	if length > uint64(maxPayload) {
		return frame{}, fmt.Errorf("%w: frame of %d bytes is too large", ErrProtocol, length)
	}
	var mask [4]byte
	if _, err := io.ReadFull(r, mask[:]); err != nil {
		return frame{}, err
	}
	f.payload = make([]byte, length)
	if _, err := io.ReadFull(r, f.payload); err != nil {
		return frame{}, err
	}
	for i := range f.payload {
		f.payload[i] ^= mask[i%4]
	}
	return f, nil
}

// appendFrame encodes one unmasked server frame.
func appendFrame(dst []byte, opcode byte, payload []byte) []byte {
	dst = append(dst, 0x80|opcode)
	switch n := len(payload); {
	case n < 126:
		dst = append(dst, byte(n))
	case n <= 0xFFFF:
		dst = append(dst, 126, byte(n>>8), byte(n))
	default:
		dst = append(dst, 127)
		dst = binary.BigEndian.AppendUint64(dst, uint64(n))
	}
	return append(dst, payload...)
}

// Conn is one accepted WebSocket connection.
type Conn struct {
	c  net.Conn
	br *bufio.Reader

	wmu    sync.Mutex
	closed bool
}

// Upgrade performs the server handshake. checkOrigin decides whether the
// browser's Origin header is acceptable (a missing Origin is passed as "").
// On failure it has already answered the request.
func Upgrade(w http.ResponseWriter, r *http.Request, checkOrigin func(origin string) bool) (*Conn, error) {
	fail := func(status int, msg string) (*Conn, error) {
		http.Error(w, msg, status)
		return nil, fmt.Errorf("websocket handshake refused: %s", msg)
	}
	if r.Method != http.MethodGet {
		return fail(http.StatusMethodNotAllowed, "GET required")
	}
	if !headerHasToken(r.Header, "Connection", "upgrade") || !headerHasToken(r.Header, "Upgrade", "websocket") {
		return fail(http.StatusBadRequest, "not a websocket request")
	}
	if r.Header.Get("Sec-WebSocket-Version") != "13" {
		w.Header().Set("Sec-WebSocket-Version", "13")
		return fail(http.StatusUpgradeRequired, "unsupported websocket version")
	}
	if !checkOrigin(r.Header.Get("Origin")) {
		return fail(http.StatusForbidden, "origin not allowed")
	}
	key := r.Header.Get("Sec-WebSocket-Key")
	if raw, err := base64.StdEncoding.DecodeString(key); err != nil || len(raw) != 16 {
		return fail(http.StatusBadRequest, "bad websocket key")
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		return fail(http.StatusInternalServerError, "cannot take over the connection")
	}
	c, brw, err := hj.Hijack()
	if err != nil {
		return nil, err
	}
	sum := sha1.Sum([]byte(key + handshakeGUID))
	resp := "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " +
		base64.StdEncoding.EncodeToString(sum[:]) + "\r\n\r\n"
	_ = c.SetWriteDeadline(time.Now().Add(5 * time.Second))
	if _, err := c.Write([]byte(resp)); err != nil {
		c.Close()
		return nil, err
	}
	_ = c.SetWriteDeadline(time.Time{})
	return &Conn{c: c, br: brw.Reader}, nil
}

func headerHasToken(h http.Header, name, token string) bool {
	for _, v := range h.Values(name) {
		for _, part := range strings.Split(v, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}
	return false
}

// SetReadDeadline bounds the next ReadMessage.
func (c *Conn) SetReadDeadline(t time.Time) error { return c.c.SetReadDeadline(t) }

// ReadMessage returns the next text or binary message. Pings are answered and
// pongs ignored along the way; a close frame is returned as *CloseError after
// it has been answered.
func (c *Conn) ReadMessage() (text bool, data []byte, err error) {
	var buf []byte
	var first byte
	started := false
	for {
		f, err := readFrame(c.br, MaxMessage)
		if err != nil {
			if errors.Is(err, ErrProtocol) {
				_ = c.Close(CloseProtocol, "protocol error")
			}
			return false, nil, err
		}
		switch f.opcode {
		case opPing:
			if err := c.write(opPong, f.payload); err != nil {
				return false, nil, err
			}
			continue
		case opPong:
			continue
		case opClose:
			code := CloseNormal
			if len(f.payload) >= 2 {
				code = int(binary.BigEndian.Uint16(f.payload))
			}
			_ = c.Close(CloseNormal, "")
			return false, nil, &CloseError{Code: code}
		case opContinuation:
			if !started {
				_ = c.Close(CloseProtocol, "unexpected continuation")
				return false, nil, fmt.Errorf("%w: continuation without a message", ErrProtocol)
			}
		default: // text or binary
			if started {
				_ = c.Close(CloseProtocol, "new message inside a fragmented one")
				return false, nil, fmt.Errorf("%w: interleaved messages", ErrProtocol)
			}
			started, first = true, f.opcode
		}
		if len(buf)+len(f.payload) > MaxMessage {
			_ = c.Close(CloseTooBig, "message too large")
			return false, nil, fmt.Errorf("%w: message too large", ErrProtocol)
		}
		buf = append(buf, f.payload...)
		if f.fin {
			if first == opText && !utf8.Valid(buf) {
				_ = c.Close(CloseInvalidData, "invalid text")
				return false, nil, fmt.Errorf("%w: text is not UTF-8", ErrProtocol)
			}
			return first == opText, buf, nil
		}
	}
}

// WriteBinary sends terminal output.
func (c *Conn) WriteBinary(p []byte) error { return c.write(opBinary, p) }

// WriteText sends a control message.
func (c *Conn) WriteText(p []byte) error { return c.write(opText, p) }

func (c *Conn) write(opcode byte, payload []byte) error {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.closed {
		return net.ErrClosed
	}
	_ = c.c.SetWriteDeadline(time.Now().Add(10 * time.Second))
	_, err := c.c.Write(appendFrame(nil, opcode, payload))
	return err
}

// Close sends a close frame (once) and closes the connection.
func (c *Conn) Close(code int, reason string) error {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.closed {
		return nil
	}
	c.closed = true
	if len(reason) > maxControl-2 {
		reason = reason[:maxControl-2]
	}
	payload := binary.BigEndian.AppendUint16(nil, uint16(code))
	payload = append(payload, reason...)
	_ = c.c.SetWriteDeadline(time.Now().Add(2 * time.Second))
	_, _ = c.c.Write(appendFrame(nil, opClose, payload))
	return c.c.Close()
}
