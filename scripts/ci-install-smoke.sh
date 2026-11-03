#!/usr/bin/env bash
# Installs a release tarball on this machine and checks that the panel really
# comes up. Meant for a throw-away Debian or Ubuntu machine or container that
# runs systemd (CI), never for a server that matters.
#
#   scripts/ci-install-smoke.sh TARBALL [--no-docker]
#
# It checks what has broken real installs before: the PHP version a release
# needs, a web server that rewrites the Host header (the panel then builds
# links without its port), services that do not start, and an upgrade over an
# existing install.
set -Eeuo pipefail

TARBALL="$(cd "$(dirname "${1:?usage: ci-install-smoke.sh TARBALL [--no-docker]}")" && pwd)/$(basename "$1")"
shift
EXTRA=("$@")
INSTALL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/installer/install.sh"

ok() { printf '\033[1;32mok\033[0m   %s\n' "$*"; }
fail() { printf '\033[1;31mFAIL\033[0m %s\n' "$*" >&2; show_logs; exit 1; }

show_logs() {
    tail -n 40 /var/log/commypanel-install.log 2>/dev/null || true
    journalctl -u 'commypanel-*' -n 30 --no-pager 2>/dev/null || true
    tail -n 20 /var/log/nginx/error.log 2>/dev/null || true
}

[ "$(id -u)" = 0 ] || fail "run as root"
[ -d /run/systemd/system ] || fail "this machine is not running systemd"

install_it() {
    COMMYPANEL_ADDRESS=127.0.0.1 bash "$INSTALL" --tarball "$TARBALL" --yes --verbose "${EXTRA[@]}" \
        >/tmp/commypanel-ci-install.log 2>&1 || { cat /tmp/commypanel-ci-install.log; fail "$1 failed"; }
    ok "$1"
}

check_running() {
    local unit
    for unit in commypanel-agent commypanel-queue@1 commypanel-scheduler nginx; do
        systemctl is-active --quiet "$unit" || fail "$unit is not running"
    done
    ok "services are running"

    [ -S /run/commypanel/agent.sock ] || fail "the agent socket is missing"
    ok "the agent socket exists"

    local code
    code="$(curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:8443/up || true)"
    [ "$code" = 200 ] || fail "/up answered $code"
    ok "/up answers 200"
}

# The setup page is a blank page when its scripts cannot load, which is how a
# wrong Host header or a wrong APP_URL shows up for a person.
check_page() {
    local url html assets asset code
    url="$(commypanel setup-url | grep -Eo 'https://[^[:space:]]+' | head -n 1)"
    [ -n "$url" ] || fail "no setup URL"
    html="$(curl -sk "$url")"
    assets="$(printf '%s' "$html" | grep -Eo 'https://[^"]+/build/assets/[^"]+' | sort -u)"
    [ -n "$assets" ] || fail "the setup page links to no assets"

    while IFS= read -r asset; do
        case "$asset" in https://127.0.0.1:8443/*) ;; *) fail "asset link without the panel's port: $asset" ;; esac
        code="$(curl -sk -o /dev/null -w '%{http_code}' "$asset")"
        [ "$code" = 200 ] || fail "$asset answered $code"
    done <<< "$assets"
    ok "the setup page and its $(printf '%s\n' "$assets" | wc -l | tr -d ' ') assets load on the panel's port"
}

# Let's Encrypt fetches its challenge files over plain HTTP from the agent's own folder,
# which only root may change but nginx must be able to read.
check_acme_webroot() {
    local dir=/var/lib/commypanel-agent/acme-webroot/.well-known/acme-challenge body
    mkdir -p "$dir" && printf 'ci-challenge' > "$dir/ci-token" && chmod 644 "$dir/ci-token"
    body="$(curl -s -H 'Host: not-a-known-site.example' http://127.0.0.1/.well-known/acme-challenge/ci-token || true)"
    rm -f "$dir/ci-token"
    [ "$body" = ci-challenge ] || fail "nginx did not serve a challenge file from the agent's folder (got: ${body:-nothing})"
    ok "nginx serves ACME challenge files from the agent's private folder"
}

# The panel finds the server's public address through the agent, as the panel user.
check_address_detection() {
    local out
    out="$(runuser -u commypanel -- curl -s --unix-socket /run/commypanel/agent.sock http://agent/v1/system/addresses || true)"
    case "$out" in *'"ipv4":['*'"ipv6":['*) ok "the agent lists the public addresses on the interfaces" ;; *) fail "the agent's addresses call answered: ${out:-nothing}" ;; esac
}

install_it "install"
check_running
check_page
check_acme_webroot
check_address_detection

install_it "upgrade over the same version"
check_running
check_page

status="$(commypanel status)"
case "$status" in Version:*) ;; *) fail "commypanel status printed no version" ;; esac
ok "commypanel status works"
printf '\n\033[1;32mAll checks passed.\033[0m\n'
