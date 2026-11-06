package dbremote

import (
	"encoding/binary"
	"errors"
	"io"
)

// The proxy never terminates TLS: it only checks that the client asks for it
// before any credential can be sent, then relays bytes. These functions read the
// few bytes that decide that, with fixed-size buffers (a length sent by the
// client never decides how much is allocated or read).

var (
	errNotTLS   = errors.New("the client did not ask for TLS")
	errProtocol = errors.New("unexpected first message")
)

const (
	pgSSLRequest    = 80877103 // 0x04d2162f
	pgGSSENCRequest = 80877104 // 0x04d21630
	pgCancelRequest = 80877102 // 0x04d2162e
	pgHeader        = 8

	mysqlMaxHandshake = 4096 // a server handshake is a few hundred bytes
	mysqlSSLRequest   = 32
	mysqlClientSSL    = 0x00000800
)

// postgresFirstStep reads the client's first message(s). It returns the bytes to
// forward to the server and whether the connection is a cancel request (which
// carries no TLS and opens its own connection). A GSSENCRequest is answered with
// 'N' on client, once, and the next message is read. Anything but SSLRequest or
// CancelRequest is an error.
func postgresFirstStep(client io.ReadWriter) (forward []byte, cancel bool, err error) {
	for attempt := 0; attempt < 2; attempt++ {
		var head [pgHeader]byte
		if _, err := io.ReadFull(client, head[:]); err != nil {
			return nil, false, err
		}
		length := binary.BigEndian.Uint32(head[0:4])
		code := binary.BigEndian.Uint32(head[4:8])
		switch {
		case length == 8 && code == pgSSLRequest:
			return head[:], false, nil
		case length == 8 && code == pgGSSENCRequest && attempt == 0:
			if _, err := client.Write([]byte{'N'}); err != nil {
				return nil, false, err
			}
			continue
		case length == 16 && code == pgCancelRequest:
			var rest [8]byte
			if _, err := io.ReadFull(client, rest[:]); err != nil {
				return nil, false, err
			}
			return append(head[:], rest[:]...), true, nil
		default:
			// A plain startup message (or anything else) would carry the login in the clear.
			return nil, false, errNotTLS
		}
	}
	return nil, false, errNotTLS
}

// mysqlRelayHandshake reads the server's first packet and passes it to the client.
func mysqlRelayHandshake(server io.Reader, client io.Writer) error {
	var head [4]byte
	if _, err := io.ReadFull(server, head[:]); err != nil {
		return err
	}
	n := int(head[0]) | int(head[1])<<8 | int(head[2])<<16
	if n == 0 || n > mysqlMaxHandshake || head[3] != 0 {
		return errProtocol
	}
	buf := make([]byte, 4+n)
	copy(buf, head[:])
	if _, err := io.ReadFull(server, buf[4:]); err != nil {
		return err
	}
	_, err := client.Write(buf)
	return err
}

// mysqlSSLRequestPacket reads the client's answer to the handshake: it must be
// the SSL request (32 bytes, sequence 1, CLIENT_SSL set), which is returned to be forwarded.
func mysqlSSLRequestPacket(client io.Reader) ([]byte, error) {
	var buf [4 + mysqlSSLRequest]byte
	if _, err := io.ReadFull(client, buf[:4]); err != nil {
		return nil, err
	}
	if err := checkMySQLHeader(buf[:4]); err != nil {
		return nil, err
	}
	if _, err := io.ReadFull(client, buf[4:]); err != nil {
		return nil, err
	}
	if binary.LittleEndian.Uint32(buf[4:8])&mysqlClientSSL == 0 {
		return nil, errNotTLS
	}
	return buf[:], nil
}

func checkMySQLHeader(head []byte) error {
	n := int(head[0]) | int(head[1])<<8 | int(head[2])<<16
	if n != mysqlSSLRequest || head[3] != 1 {
		// A full login packet is longer than 32 bytes: it would carry the credentials without TLS.
		return errNotTLS
	}
	return nil
}
