package dbremote

import (
	"bytes"
	"encoding/binary"
	"io"
	"testing"
)

// rw reads from in and records what is written.
type rw struct {
	io.Reader
	out bytes.Buffer
}

func (r *rw) Write(p []byte) (int, error) { return r.out.Write(p) }

func pgMsg(length, code uint32, extra ...byte) []byte {
	b := make([]byte, 8)
	binary.BigEndian.PutUint32(b, length)
	binary.BigEndian.PutUint32(b[4:], code)
	return append(b, extra...)
}

func TestPostgresFirstStep(t *testing.T) {
	ssl := pgMsg(8, 80877103)
	gss := pgMsg(8, 80877104)
	cancel := pgMsg(16, 80877102, 1, 2, 3, 4, 5, 6, 7, 8)
	startup := pgMsg(41, 196608, make([]byte, 33)...)
	tests := []struct {
		name       string
		in         []byte
		forward    []byte
		cancel     bool
		reply      string
		wantErr    bool
		wantUnread int
	}{
		{"ssl request", ssl, ssl, false, "", false, 0},
		{"ssl request leaves what follows unread", append(append([]byte{}, ssl...), 'x', 'y'), ssl, false, "", false, 2},
		{"gssenc then ssl", append(append([]byte{}, gss...), ssl...), ssl, false, "N", false, 0},
		{"gssenc twice", append(append([]byte{}, gss...), gss...), nil, false, "N", true, 0},
		{"gssenc then plain", append(append([]byte{}, gss...), startup...), nil, false, "N", true, 0},
		{"cancel request", cancel, cancel, true, "", false, 0},
		{"cancel cut short", cancel[:12], nil, false, "", true, 0},
		{"plain startup", startup, nil, false, "", true, 0},
		{"ssl with another length", pgMsg(9, 80877103), nil, false, "", true, 0},
		{"cancel with another length", pgMsg(8, 80877102), nil, false, "", true, 0},
		{"empty", nil, nil, false, "", true, 0},
		{"short", []byte{0, 0, 0}, nil, false, "", true, 0},
		{"zeros", make([]byte, 8), nil, false, "", true, 0},
	}
	for _, tt := range tests {
		r := &rw{Reader: bytes.NewReader(tt.in)}
		fwd, cancel, err := postgresFirstStep(r)
		if (err != nil) != tt.wantErr {
			t.Errorf("%s: err = %v", tt.name, err)
			continue
		}
		if !bytes.Equal(fwd, tt.forward) || cancel != tt.cancel || r.out.String() != tt.reply {
			t.Errorf("%s: forward %x cancel %v reply %q", tt.name, fwd, cancel, r.out.String())
		}
		if rest := r.Reader.(*bytes.Reader).Len(); !tt.wantErr && rest != tt.wantUnread {
			t.Errorf("%s: %d bytes unread, want %d (nothing beyond the first message may be consumed)", tt.name, rest, tt.wantUnread)
		}
	}
}

func mysqlPacket(seq byte, payload []byte) []byte {
	return append([]byte{byte(len(payload)), byte(len(payload) >> 8), byte(len(payload) >> 16), seq}, payload...)
}

func sslRequestPayload(caps uint32) []byte {
	p := make([]byte, 32)
	binary.LittleEndian.PutUint32(p, caps)
	return p
}

func TestMySQLSSLRequestPacket(t *testing.T) {
	good := mysqlPacket(1, sslRequestPayload(0x0000f9a5|0x800))
	tests := []struct {
		name    string
		in      []byte
		wantErr bool
	}{
		{"ssl request", good, false},
		{"ssl request, only the flag", mysqlPacket(1, sslRequestPayload(0x800)), false},
		{"no ssl flag", mysqlPacket(1, sslRequestPayload(0xf7a5)), true},
		{"login packet (longer than 32)", mysqlPacket(1, append(sslRequestPayload(0xffff), make([]byte, 40)...)), true},
		{"wrong sequence", mysqlPacket(2, sslRequestPayload(0x800)), true},
		{"sequence 0", mysqlPacket(0, sslRequestPayload(0x800)), true},
		{"short payload", mysqlPacket(1, make([]byte, 31)), true},
		{"truncated", good[:20], true},
		{"empty", nil, true},
		{"huge length claimed", []byte{0xff, 0xff, 0xff, 1}, true},
	}
	for _, tt := range tests {
		got, err := mysqlSSLRequestPacket(bytes.NewReader(tt.in))
		if (err != nil) != tt.wantErr {
			t.Errorf("%s: err = %v", tt.name, err)
			continue
		}
		if !tt.wantErr && !bytes.Equal(got, tt.in) {
			t.Errorf("%s: forwarded %x", tt.name, got)
		}
	}
}

func TestMySQLRelayHandshake(t *testing.T) {
	hs := mysqlPacket(0, append([]byte{10}, bytes.Repeat([]byte("v"), 60)...))
	var out bytes.Buffer
	if err := mysqlRelayHandshake(bytes.NewReader(append(append([]byte{}, hs...), 'z')), &out); err != nil || !bytes.Equal(out.Bytes(), hs) {
		t.Fatalf("relay: %v, %x", err, out.Bytes())
	}
	for name, in := range map[string][]byte{
		"empty":           nil,
		"zero length":     {0, 0, 0, 0},
		"sequence not 0":  mysqlPacket(1, []byte{10}),
		"huge":            {0xff, 0xff, 0xff, 0},
		"just over limit": {0x01, 0x10, 0, 0},
		"cut short":       hs[:10],
	} {
		out.Reset()
		if err := mysqlRelayHandshake(bytes.NewReader(in), &out); err == nil || out.Len() != 0 {
			t.Errorf("%s: err = %v, wrote %d bytes", name, err, out.Len())
		}
	}
}

func FuzzPostgresFirstStep(f *testing.F) {
	f.Add(pgMsg(8, 80877103))
	f.Add(pgMsg(8, 80877104))
	f.Add(pgMsg(16, 80877102, 1, 2, 3, 4, 5, 6, 7, 8))
	f.Add(pgMsg(8, 80877104))
	f.Add([]byte{})
	f.Fuzz(func(t *testing.T, in []byte) {
		r := &rw{Reader: bytes.NewReader(in)}
		fwd, cancel, err := postgresFirstStep(r)
		if r.Reader.(*bytes.Reader).Size()-int64(r.Reader.(*bytes.Reader).Len()) > 24 {
			t.Fatal("read more than three small messages")
		}
		if err == nil {
			// Accepted input is exactly an SSLRequest or a CancelRequest, nothing else.
			if cancel && len(fwd) != 16 || !cancel && !bytes.Equal(fwd, pgMsg(8, 80877103)) {
				t.Fatalf("accepted %x", fwd)
			}
		}
		if r.out.Len() > 1 {
			t.Fatal("wrote more than one byte")
		}
	})
}

func FuzzMySQLPackets(f *testing.F) {
	f.Add(mysqlPacket(1, sslRequestPayload(0x800)))
	f.Add(mysqlPacket(0, []byte{10, 'x'}))
	f.Add([]byte{0xff, 0xff, 0xff, 0})
	f.Fuzz(func(t *testing.T, in []byte) {
		pkt, err := mysqlSSLRequestPacket(bytes.NewReader(in))
		if err == nil {
			if len(pkt) != 36 || pkt[3] != 1 || binary.LittleEndian.Uint32(pkt[4:8])&0x800 == 0 {
				t.Fatalf("accepted %x", pkt)
			}
		}
		var out bytes.Buffer
		if err := mysqlRelayHandshake(bytes.NewReader(in), &out); err == nil {
			if out.Len() > 4+mysqlMaxHandshake || out.Len() < 5 {
				t.Fatalf("relayed %d bytes", out.Len())
			}
		} else if out.Len() != 0 {
			t.Fatal("wrote before failing")
		}
	})
}
