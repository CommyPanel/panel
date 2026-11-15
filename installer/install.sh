#!/usr/bin/env bash
# CommyPanel installer: downloads a release, checks its checksum, unpacks it
# and hands over to the setup script inside it.
#
#   curl -fsSL https://github.com/commypanel/panel/releases/latest/download/install.sh | sudo bash
#
# Options (all optional):
#   --version X       install version X instead of the latest
#   --upgrade         upgrade an existing installation (also automatic)
#   -y, --yes         do not ask before upgrading (for scripts)
#   --panel-port N    port for the panel's web interface (default 8443)
#   --tarball FILE    use a local release tarball instead of downloading
#   --allow-unsigned  install a release without a valid signature (at your own risk)
#   --render-only     only write config files below COMMYPANEL_ROOT (testing)
#   --verbose         show everything instead of a progress display
set -Eeuo pipefail

REPO="${COMMYPANEL_REPO:-commypanel/panel}"
PREFIX="/opt/commypanel"

VERSION="latest"
TARBALL=""
ASSUME_YES=0
UPGRADING=0
RENDER_ONLY=0
ALLOW_UNSIGNED="${COMMYPANEL_ALLOW_UNSIGNED:-0}"
OPENSSL="${COMMYPANEL_OPENSSL:-openssl}"
PASSTHROUGH=()

die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarning:\033[0m %s\n' "$*" >&2; }

while [ "$#" -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2:?--version needs a value}"; shift 2 ;;
        --tarball) TARBALL="${2:?--tarball needs a file}"; shift 2 ;;
        -y|--yes) ASSUME_YES=1; shift ;;
        --allow-unsigned) ALLOW_UNSIGNED=1; shift ;;
        --upgrade) UPGRADING=1; PASSTHROUGH+=("$1"); shift ;;
        --render-only) RENDER_ONLY=1; PASSTHROUGH+=("$1"); shift ;;
        --no-docker|--verbose) PASSTHROUGH+=("$1"); shift ;;
        --panel-port) PASSTHROUGH+=("$1" "${2:?--panel-port needs a value}"); shift 2 ;;
        -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
done

# The public half of the key that signs releases (Ed25519); the private half is
# held only by the release workflow. The same text ships in every release as
# installer/release-key.pub. An upgrade checks the new release against the key of
# the version that is installed, never against a key that arrives with the download
# itself. scripts/generate-signing-key.sh rewrites the block between the markers.
embedded_key() {
    cat <<'KEY'
# --- release key begin ---
# --- release key end ---
KEY
}

# The fingerprint (SHA-256 of the public key) people compare with the one published
# in the documentation.
key_fingerprint() {
    "$OPENSSL" pkey -pubin -in "$1" -outform DER 2>/dev/null | { sha256sum 2>/dev/null || shasum -a 256; } | cut -d' ' -f1
}

# Succeeds only for a valid Ed25519 signature of the tarball made with that key.
verify_signature() {
    local tarball=$1 sig=$2 key=$3
    "$OPENSSL" pkeyutl -verify -pubin -inkey "$key" -rawin -in "$tarball" -sigfile "$sig" >/dev/null 2>&1
}

# Decides whether the download may be used. $1 tarball, $2 signature file (may not
# exist), $3 the installed release's key file (may not exist), $4 scratch directory.
# An upgrade trusts the key of the installed version; a first install, and an upgrade
# from a version that predates signing, use the key built into this script.
check_release_signature() {
    local tarball=$1 sig=$2 installed_key=$3 work=$4 key="" source=""

    if [ "$ALLOW_UNSIGNED" = 1 ]; then
        warn "Skipping the signature check (--allow-unsigned). Only do this for a release you trust."
        return 0
    fi

    if [ -s "$installed_key" ] && grep -q 'BEGIN PUBLIC KEY' "$installed_key"; then
        key=$installed_key
        source="the key of the installed version"
    else
        embedded_key | grep -v '^#' > "$work/embedded-key.pub" || true
        if grep -q 'BEGIN PUBLIC KEY' "$work/embedded-key.pub"; then
            key="$work/embedded-key.pub"
            source="the key built into this installer"
        fi
    fi

    command -v "$OPENSSL" >/dev/null 2>&1 \
        || die "openssl is needed to check the release signature (or pass --allow-unsigned at your own risk)."
    [ -n "$key" ] || die "This installer has no release key to check the signature with. Download the installer from the release page again, or pass --allow-unsigned at your own risk."
    [ -s "$sig" ] || die "The release is not signed (it has no commypanel.tar.gz.sig), so nothing was installed. Pass --allow-unsigned only if you trust where it came from."

    log "Release signing key SHA256:$(key_fingerprint "$key") ($source); compare it with the one in the documentation."
    verify_signature "$tarball" "$sig" "$key" \
        || die "The signature does not match ($source). The download was changed or was not made by CommyPanel; nothing was installed."
    log "The release signature is valid"
}

# Asks before an upgrade, because the panel is unavailable for a moment while it
# switches. Needs a real terminal (it reads /dev/tty, so it also works when the
# script is piped from curl); without one, or with --yes, it goes ahead so
# scripts keep working.
confirm_upgrade() {
    local from=$1 to=$2 answer tty="${COMMYPANEL_TTY:-/dev/tty}"

    if [ "$ASSUME_YES" = 1 ]; then return 0; fi
    if ! { : < "$tty"; } 2>/dev/null; then
        log "No terminal to ask on, so the upgrade goes ahead (add --yes to skip this notice)."
        return 0
    fi

    if [ "$from" = "$to" ]; then
        printf '\nCommyPanel %s is already installed. This installs it again.\n' "$to"
    else
        printf '\nCommyPanel will be upgraded from %s to %s.\n' "${from:-the current version}" "$to"
    fi
    cat <<'TEXT'

  * The panel is unavailable for a short while. Its services restart and it
    switches to the new version, so the web interface does not answer until
    that is done.
  * Your apps stay online. Docker and your containers are not restarted, and
    nginx is reloaded gracefully, so domains keep serving. Deployments cannot
    start in the meantime, and a build that is running is interrupted.
  * If the new version does not start, the installer goes back to the
    version you have now by itself. Changes the new version made to the
    panel's databases are kept; they are backed up first, in
    /var/lib/commypanel/backups.

TEXT
    printf 'Continue with the upgrade? [Y/n] '
    read -r answer < "$tty" || answer=n
    case "$answer" in
        ""|y|Y|yes|Yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

[ "$(id -u)" -eq 0 ] || die "Run this as root, for example: curl -fsSL <url> | sudo bash"
for tool in tar sha256sum cut; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is required."
done

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK:?}"' EXIT

if [ -n "$TARBALL" ]; then
    [ -f "$TARBALL" ] || die "Tarball not found: $TARBALL"
    cp "$TARBALL" "$WORK/commypanel.tar.gz"
    if [ -f "$TARBALL.sha256" ]; then
        cp "$TARBALL.sha256" "$WORK/commypanel.tar.gz.sha256"
    else
        log "No $TARBALL.sha256 next to the tarball; skipping the checksum check for this local file."
    fi
    if [ -f "$TARBALL.sig" ]; then cp "$TARBALL.sig" "$WORK/commypanel.tar.gz.sig"; fi
else
    command -v curl >/dev/null 2>&1 || die "curl is required."
    if [ "$VERSION" = "latest" ]; then
        base="https://github.com/$REPO/releases/latest/download"
    else
        base="https://github.com/$REPO/releases/download/v${VERSION#v}"
    fi
    log "Downloading CommyPanel ($VERSION)"
    curl -fsSL --proto '=https' --tlsv1.2 -o "$WORK/commypanel.tar.gz" "$base/commypanel.tar.gz" \
        || die "Could not download $base/commypanel.tar.gz. Check that the release has the files commypanel.tar.gz and commypanel.tar.gz.sha256 attached: https://github.com/$REPO/releases"
    curl -fsSL --proto '=https' --tlsv1.2 -o "$WORK/commypanel.tar.gz.sha256" "$base/commypanel.tar.gz.sha256" \
        || die "Could not download $base/commypanel.tar.gz.sha256 (the release needs that file too)."
    # A release made before signing started has no signature; the check below decides what that means.
    curl -fsSL --proto '=https' --tlsv1.2 -o "$WORK/commypanel.tar.gz.sig" "$base/commypanel.tar.gz.sig" 2>/dev/null \
        || rm -f "$WORK/commypanel.tar.gz.sig"
fi

if [ -f "$WORK/commypanel.tar.gz.sha256" ]; then
    log "Verifying the checksum"
    (cd "$WORK" && sha256sum --check --status commypanel.tar.gz.sha256) \
        || die "The checksum does not match. The download is corrupt or has been tampered with; nothing was installed."
fi

# Nothing is unpacked before the signature is checked. An installation that exists
# decides which key is trusted: its own.
check_release_signature "$WORK/commypanel.tar.gz" "$WORK/commypanel.tar.gz.sig" "$PREFIX/current/installer/release-key.pub" "$WORK"

log "Unpacking"
mkdir -p "$PREFIX/releases"
incoming="$PREFIX/releases/.incoming-$$"
rm -rf "${incoming:?}"
mkdir -p "$incoming"
tar -xzf "$WORK/commypanel.tar.gz" -C "$incoming" --no-same-owner

release="$(tr -d '[:space:]' < "$incoming/VERSION" 2>/dev/null || true)"
case "$release" in
    ""|*[!A-Za-z0-9._-]*) rm -rf "${incoming:?}"; die "The release has no valid VERSION file." ;;
esac
[ -x "$incoming/installer/setup.sh" ] || { rm -rf "${incoming:?}"; die "The release does not contain installer/setup.sh."; }

# An existing installation means this is an upgrade, with or without --upgrade.
if [ -L "$PREFIX/current" ] && [ -f /etc/commypanel/env ]; then UPGRADING=1; fi
if [ "$UPGRADING" = 1 ] && [ "$RENDER_ONLY" = 0 ]; then
    installed="$(tr -d '[:space:]' < "$PREFIX/current/VERSION" 2>/dev/null || true)"
    if ! confirm_upgrade "$installed" "$release"; then
        rm -rf "${incoming:?}"
        log "Cancelled. Nothing was changed."
        exit 1
    fi
fi

rm -rf "${PREFIX:?}/releases/${release:?}"
mv "$incoming" "$PREFIX/releases/$release"

exec "$PREFIX/releases/$release/installer/setup.sh" ${PASSTHROUGH[@]+"${PASSTHROUGH[@]}"}
