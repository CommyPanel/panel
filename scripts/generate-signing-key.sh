#!/usr/bin/env bash
# Makes the Ed25519 key pair that signs CommyPanel releases.
#
#   scripts/generate-signing-key.sh [PRIVATE_KEY_FILE]
#
# The PRIVATE key is written to PRIVATE_KEY_FILE (default ./commypanel-release-signing.key,
# mode 0600) and must never be committed: it goes into the GitHub secret
# RELEASE_SIGNING_KEY and into your password manager, then the file is deleted. The PUBLIC key
# is written into installer/release-key.pub and into the marked block of installer/install.sh,
# and its fingerprint is printed so it can be published in the documentation.
# Refuses to overwrite an existing private key file or an installer that already has a key
# (a new key means every installed panel must be re-trusted by hand): pass --replace to rotate.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OPENSSL="${COMMYPANEL_OPENSSL:-openssl}"
REPLACE=0
OUT=""

for arg in "$@"; do
    case "$arg" in
        --replace) REPLACE=1 ;;
        -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) OUT="$arg" ;;
    esac
done
OUT="${OUT:-$PWD/commypanel-release-signing.key}"

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v "$OPENSSL" >/dev/null 2>&1 || die "openssl is required."
case "$OUT" in "$ROOT"/*) die "Keep the private key outside the repository (not $OUT)." ;; esac
[ ! -e "$OUT" ] || die "$OUT exists already; refusing to overwrite a private key."
if [ "$REPLACE" = 0 ] && grep -q '^-----BEGIN PUBLIC KEY' "$ROOT/installer/install.sh"; then
    die "installer/install.sh already has a release key. Pass --replace only if you mean to rotate it."
fi

(umask 077 && "$OPENSSL" genpkey -algorithm ed25519 -out "$OUT") \
    || die "This openssl cannot make Ed25519 keys (needs OpenSSL 1.1.1 or newer; macOS's LibreSSL is too old, try Homebrew's: COMMYPANEL_OPENSSL=/opt/homebrew/bin/openssl)."
chmod 600 "$OUT"

pub="$(mktemp)"
trap 'rm -f "$pub"' EXIT
"$OPENSSL" pkey -in "$OUT" -pubout -out "$pub"
cp "$pub" "$ROOT/installer/release-key.pub"

# Replace everything between the markers in install.sh with the public key.
tmp="$(mktemp)"
awk -v keyfile="$pub" '
    /^# --- release key begin ---$/ { print; while ((getline line < keyfile) > 0) print line; skip = 1; next }
    /^# --- release key end ---$/ { skip = 0 }
    !skip { print }
' "$ROOT/installer/install.sh" > "$tmp"
cat "$tmp" > "$ROOT/installer/install.sh"
rm -f "$tmp"

fingerprint="$("$OPENSSL" pkey -pubin -in "$pub" -outform DER | { sha256sum 2>/dev/null || shasum -a 256; } | cut -d' ' -f1)"

cat <<EOF

Private key written to: $OUT   (keep it secret, never commit it)
Public key written to:  installer/release-key.pub and installer/install.sh
Fingerprint:            SHA256:$fingerprint

Next:
  1. Store the private key as the repository secret RELEASE_SIGNING_KEY:
       gh secret set RELEASE_SIGNING_KEY --repo <owner>/<repo> < "$OUT"
  2. Keep a copy of the private key in your password manager, then delete the file.
  3. Publish the fingerprint above in the documentation (docs/installing.md, "Check a release by hand").
  4. Commit installer/release-key.pub and installer/install.sh, then tag a release.
EOF
