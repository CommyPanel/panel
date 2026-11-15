#!/usr/bin/env bash
# Builds a CommyPanel release tarball.
#
#   scripts/build-release.sh VERSION [OUTPUT_DIR]
#
# Produces OUTPUT_DIR/commypanel.tar.gz and commypanel.tar.gz.sha256 (default
# OUTPUT_DIR: dist/). The tarball holds the production PHP app (no dev
# dependencies), the built frontend, the agent for amd64 and arm64, and the
# installer. It needs composer, npm, go and tar. Set COMMYPANEL_SKIP_NPM_INSTALL=1
# to reuse an existing node_modules instead of running `npm ci`.
set -Eeuo pipefail

VERSION="${1:?usage: scripts/build-release.sh VERSION [OUTPUT_DIR]}"
case "$VERSION" in
    ""|*[!A-Za-z0-9._-]*) echo "error: VERSION may only contain letters, digits, dots, dashes and underscores." >&2; exit 1 ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${2:-$ROOT/dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE:?}"' EXIT

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}

# A release without a key to check it against cannot be verified by anyone, so refuse to publish one
# (COMMYPANEL_UNSIGNED_BUILD=1 is for local test builds only).
if ! grep -q '^-----BEGIN PUBLIC KEY' "$ROOT/installer/release-key.pub" && [ "${COMMYPANEL_UNSIGNED_BUILD:-0}" != 1 ]; then
    { echo "error: installer/release-key.pub has no public key: run scripts/generate-signing-key.sh first (or set COMMYPANEL_UNSIGNED_BUILD=1 for a local build)." >&2; exit 1; }
fi

log "Building the frontend"
if [ "${COMMYPANEL_SKIP_NPM_INSTALL:-0}" != 1 ]; then
    (cd "$ROOT" && npm ci --silent)
fi
(cd "$ROOT" && npm run build --silent)

log "Collecting the application"
for path in app bootstrap config database lang public resources/views routes artisan composer.json composer.lock LICENSE installer; do
    mkdir -p "$STAGE/$(dirname "$path")"
    cp -R "$ROOT/$path" "$STAGE/$path"
done

# The translations are read at runtime (lang/<code>.json and lang/<code>/*.php): a release without them
# shows every language as English, so refuse to build one.
for file in lang/en.json lang/fr.json lang/de.json lang/es.json lang/ru.json lang/uk.json lang/tr.json lang/fr/validation.php; do
    [ -f "$STAGE/$file" ] || { printf 'error: %s is missing from the release\n' "$file" >&2; exit 1; }
done

# Runtime state and local leftovers never ship.
rm -rf "$STAGE/bootstrap/cache"
mkdir -p "$STAGE/bootstrap/cache"
touch "$STAGE/bootstrap/cache/.gitignore"
rm -f "$STAGE"/database/*.sqlite "$STAGE"/database/*.sqlite-* "$STAGE/public/hot"
rm -rf "$STAGE/public/storage"
find "$STAGE" \( -name '.DS_Store' -o -name '._*' \) -delete

log "Installing production dependencies"
(cd "$STAGE" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-scripts --no-interaction --prefer-dist --quiet)

log "Building the agent (linux/amd64 and linux/arm64)"
mkdir -p "$STAGE/bin"
for arch in amd64 arm64; do
    (cd "$ROOT/agent" && CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath \
        -ldflags "-s -w -X main.version=$VERSION" \
        -o "$STAGE/bin/commypanel-agent-linux-$arch" ./cmd/commypanel-agent)
done

printf '%s\n' "$VERSION" > "$STAGE/VERSION"
# mktemp creates 0700; the tarball's top folder must stay readable for the panel user.
chmod 755 "$STAGE"
chmod +x "$STAGE/installer/install.sh" "$STAGE/installer/setup.sh" "$STAGE/artisan"

log "Packing"
export COPYFILE_DISABLE=1
if tar --version 2>/dev/null | grep -q 'GNU tar'; then
    tar -czf "$OUT/commypanel.tar.gz" --owner=0 --group=0 -C "$STAGE" .
else
    tar -czf "$OUT/commypanel.tar.gz" --uid 0 --gid 0 -C "$STAGE" .
fi
(cd "$OUT" && sha256 commypanel.tar.gz > commypanel.tar.gz.sha256)

# The one-line installer is published next to the tarball.
cp "$ROOT/installer/install.sh" "$OUT/install.sh"

log "Built $OUT/commypanel.tar.gz ($(du -h "$OUT/commypanel.tar.gz" | cut -f1))"
cat "$OUT/commypanel.tar.gz.sha256"
