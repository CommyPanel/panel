#!/usr/bin/env bash
# CommyPanel setup. Installs CommyPanel on this server, or upgrades an existing
# installation. It runs from an unpacked release (/opt/commypanel/releases/<version>)
# and is safe to run again: a second run on an installed server is an upgrade.
#
# Normally you do not run this directly; install.sh and `commypanel upgrade` do.
#
# Options:
#   --panel-port N   port of the panel's web interface (default 8443)
#   --no-docker      do not install Docker (you manage it yourself)
#   --upgrade        require an existing installation
#   --render-only    write config files below $COMMYPANEL_ROOT and touch nothing else
#   --verbose        show everything the installer does instead of a progress display
set -Eeuo pipefail

RELEASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATES="$RELEASE_DIR/installer/templates"
# shellcheck source=installer/php-security.sh
. "$RELEASE_DIR/installer/php-security.sh"

ROOT="${COMMYPANEL_ROOT:-}"
PREFIX="/opt/commypanel"
DATA_DIR="/var/lib/commypanel"
CONF_DIR="/etc/commypanel"
NGINX_DIR="/etc/nginx"
PANEL_PORT="${COMMYPANEL_PANEL_PORT:-8443}"
# Loopback-only plain-HTTP listener: the agent proxies the panel's own domain to it.
PANEL_INTERNAL_PORT=8081
LOG_FILE="/var/log/commypanel-install.log"

UPGRADE_FLAG=0
RENDER_ONLY=0
SKIP_DOCKER=0
VERBOSE=0

# Descriptor 3 is the terminal for the progress display. The installer's own
# output goes to the log file unless --verbose (or no terminal) is in effect.
exec 3>&1

while [ "$#" -gt 0 ]; do
    case "$1" in
        --panel-port) PANEL_PORT="${2:?--panel-port needs a value}"; shift 2 ;;
        --no-docker) SKIP_DOCKER=1; shift ;;
        --upgrade) UPGRADE_FLAG=1; shift ;;
        --render-only) RENDER_ONLY=1; shift ;;
        --verbose) VERBOSE=1; shift ;;
        -h|--help) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; exit 1 ;;
    esac
done

SYSTEM=1
if [ "$RENDER_ONLY" = 1 ]; then SYSTEM=0; fi

case "$PANEL_PORT" in
    ""|*[!0-9]*) printf 'error: --panel-port must be a number.\n' >&2; exit 1 ;;
esac
if [ "$PANEL_PORT" -lt 1024 ] || [ "$PANEL_PORT" -gt 65535 ] || [ "$PANEL_PORT" = 8080 ]; then
    printf 'error: --panel-port must be between 1024 and 65535 (and not 8080, which apps commonly use).\n' >&2
    exit 1
fi
if [ "$RENDER_ONLY" = 1 ] && [ -z "$ROOT" ]; then
    printf 'error: --render-only needs COMMYPANEL_ROOT to point at a scratch directory.\n' >&2
    exit 1
fi

# ----------------------------------------------------------------- helpers

# ---------------------------------------------------------------- display
#
# Fancy mode (a real terminal, no --verbose): one line per step with a spinner,
# a progress bar and the elapsed time; all other output goes to the log file,
# whose tail is shown if something fails. Plain mode (pipes, CI, tests,
# --verbose): numbered lines, everything printed as it happens.

UI_FANCY=0
UI_COLOR=0
UI_UTF=0
STEP_NO=0
STEP_TOTAL=8
STEP_LABEL=""
STEP_PID=""
STEP_START=0

ui_init() {
    if [ -t 3 ] && [ "${TERM:-dumb}" != dumb ] && [ -z "${NO_COLOR:-}" ]; then UI_COLOR=1; fi
    if [ "$UI_COLOR" = 1 ] && [ "$SYSTEM" = 1 ] && [ "$VERBOSE" = 0 ]; then UI_FANCY=1; fi
    case "${LC_ALL:-${LC_CTYPE:-${LANG:-}}}" in
        *UTF-8*|*utf-8*|*UTF8*|*utf8*) UI_UTF=1 ;;
    esac
    if [ "$UI_COLOR" = 1 ]; then
        C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
        C_RED=$'\033[1;31m'; C_GREEN=$'\033[1;32m'; C_YELLOW=$'\033[1;33m'; C_BLUE=$'\033[1;34m'
    else
        C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
    fi
    if [ "$UI_UTF" = 1 ]; then
        B_ON="█"; B_OFF="░"; MARK_OK="✔"; MARK_FAIL="✘"; FRAMES=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
    else
        B_ON="#"; B_OFF="-"; MARK_OK="ok"; MARK_FAIL="FAILED"; FRAMES=('|' '/' '-' '\\')
    fi
}
C_RESET=""; C_BOLD=""; C_DIM=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""
B_ON="#"; B_OFF="-"; MARK_OK="ok"; MARK_FAIL="FAILED"; FRAMES=('|' '/' '-' '\\')

# say FORMAT [ARGS]: a line for the person at the terminal.
say() {
    if [ "$UI_FANCY" = 1 ]; then printf "$@" >&3; else printf "$@"; fi
}

# Messages that must reach the terminal even in fancy mode.
ui_err() {
    if [ "$UI_FANCY" = 1 ]; then printf '%s\n' "$*" >&3; else printf '%s\n' "$*" >&2; fi
}

progress_bar() {
    local filled=$1 total=$2 width=20 n i=0 out=""
    n=$(( total > 0 ? filled * width / total : 0 ))
    while [ "$i" -lt "$width" ]; do
        if [ "$i" -lt "$n" ]; then out="$out$B_ON"; else out="$out$B_OFF"; fi
        i=$((i + 1))
    done
    printf '%s' "$out"
}

spinner_loop() {
    local i=0 frame
    while :; do
        frame="${FRAMES[$((i % ${#FRAMES[@]}))]}"
        printf '\r\033[K  %s%s%s %s%s%s  %s%s %d/%d  %ds%s' \
            "$C_BLUE" "$frame" "$C_RESET" "$C_BOLD" "$STEP_LABEL" "$C_RESET" \
            "$C_DIM" "$(progress_bar "$((STEP_NO - 1))" "$STEP_TOTAL")" "$STEP_NO" "$STEP_TOTAL" "$((SECONDS - STEP_START))" "$C_RESET" >&3
        i=$((i + 1))
        sleep 0.1
    done
}

spinner_stop() {
    if [ -n "$STEP_PID" ]; then
        kill "$STEP_PID" 2>/dev/null || true
        wait "$STEP_PID" 2>/dev/null || true
        STEP_PID=""
        printf '\r\033[K' >&3
    fi
}

step_start() {
    STEP_NO=$((STEP_NO + 1))
    STEP_LABEL=$1
    STEP_START=$SECONDS
    if [ "$UI_FANCY" = 1 ]; then
        printf '\n==> [%d/%d] %s\n' "$STEP_NO" "$STEP_TOTAL" "$STEP_LABEL"
        spinner_loop &
        STEP_PID=$!
        disown "$STEP_PID" 2>/dev/null || true
    else
        say '%s[%d/%d]%s %s\n' "$C_BLUE" "$STEP_NO" "$STEP_TOTAL" "$C_RESET" "$STEP_LABEL"
    fi
}

step_done() {
    local took=$((SECONDS - STEP_START))
    if [ "$UI_FANCY" = 1 ]; then
        spinner_stop
        printf '  %s%s%s %s  %s%ds%s\n' "$C_GREEN" "$MARK_OK" "$C_RESET" "$STEP_LABEL" "$C_DIM" "$took" "$C_RESET" >&3
    fi
    STEP_LABEL=""
}

# step "Label" function [args]: run one stage of the installation.
step() {
    local label=$1
    shift
    step_start "$label"
    "$@"
    step_done
}

banner() {
    say '\n%sCommyPanel installer%s  %s%s%s\n' "$C_BOLD" "$C_RESET" "$C_DIM" "$1" "$C_RESET"
    if [ "$UI_FANCY" = 1 ]; then say '%sFull log: %s%s\n\n' "$C_DIM" "$LOG_FILE" "$C_RESET"; else say '\n'; fi
}

show_log_tail() {
    if [ "$UI_FANCY" = 1 ] && [ -f "$LOG_FILE" ]; then
        printf '\n%sLast lines of the log:%s\n' "$C_DIM" "$C_RESET" >&3
        tail -n 20 "$LOG_FILE" | sed 's/^/    /' >&3
        printf '\n' >&3
    fi
}

log() {
    if [ "$UI_FANCY" = 1 ]; then printf '==> %s\n' "$*"; else printf '%s==>%s %s\n' "$C_BLUE" "$C_RESET" "$*"; fi
}
warn() {
    if [ "$UI_FANCY" = 1 ]; then
        printf 'warning: %s\n' "$*"
        printf '\r\033[K  %swarning:%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&3
    else
        printf '%swarning:%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2
    fi
}
die() {
    local was_running="$STEP_PID"
    spinner_stop
    if [ "$UI_FANCY" = 1 ] && [ -n "$was_running" ]; then
        printf '  %s%s%s %s\n' "$C_RED" "$MARK_FAIL" "$C_RESET" "$STEP_LABEL" >&3
    fi
    ui_err "${C_RED}error:${C_RESET} $*"
    if [ "$UI_FANCY" = 1 ]; then printf 'error: %s\n' "$*"; fi
    show_log_tail
    exit 1
}

on_error() {
    local status=$1 line=$2 was_running="$STEP_PID"
    trap - ERR
    spinner_stop
    if [ "$UI_FANCY" = 1 ] && [ -n "$was_running" ]; then
        printf '  %s%s%s %s\n' "$C_RED" "$MARK_FAIL" "$C_RESET" "$STEP_LABEL" >&3
    fi
    ui_err "${C_RED}error:${C_RESET} Setup stopped at line $line (exit status $status)."
    if [ "$UI_FANCY" = 1 ]; then printf 'error: Setup stopped at line %s (exit status %s).\n' "$line" "$status"; fi
    show_log_tail
    if [ "$SYSTEM" = 1 ]; then ui_err "The full log is in $LOG_FILE"; fi
    exit "$status"
}
trap 'on_error $? $LINENO' ERR
trap 'spinner_stop' EXIT

# Path below the (test) root.
p() { printf '%s%s' "$ROOT" "$1"; }

# Run a system-changing command, unless we are only rendering files.
sys() {
    if [ "$SYSTEM" = 1 ]; then
        "$@"
    fi
}

# mkd MODE OWNER GROUP PATH: create a directory with the given ownership.
mkd() {
    if [ "$SYSTEM" = 1 ]; then
        install -d -m "$1" -o "$2" -g "$3" "$4"
    else
        mkdir -p "$4"
    fi
}

# Escape a value for use on the right-hand side of a sed substitution.
sed_escape() { printf '%s' "$1" | sed -e 's/[\\&#]/\\&/g'; }

# For a php-fpm setting that only exists from PHP 8.5: nothing on 8.5 and newer,
# and a comment marker on older versions, which turns the line into a comment.
php85_only() {
    if php_version_ge "${PHP_VER:-0}" 8.5; then return 0; fi
    printf ';'
}

# render SRC DEST MODE: fill in the @@PLACEHOLDERS@@ of a template.
render() {
    local src=$1 dest=$2 mode=$3
    mkdir -p "$(dirname "$dest")"
    sed \
        -e "s#@@PREFIX@@#$(sed_escape "$PREFIX")#g" \
        -e "s#@@DATA_DIR@@#$(sed_escape "$DATA_DIR")#g" \
        -e "s#@@CONF_DIR@@#$(sed_escape "$CONF_DIR")#g" \
        -e "s#@@PANEL_PORT@@#$(sed_escape "$PANEL_PORT")#g" \
        -e "s#@@PANEL_INTERNAL_PORT@@#$(sed_escape "$PANEL_INTERNAL_PORT")#g" \
        -e "s#@@PANEL_UID@@#$(sed_escape "${PANEL_UID:-}")#g" \
        -e "s#@@PHP_BIN@@#$(sed_escape "${PHP_BIN:-}")#g" \
        -e "s#@@PHP85_ONLY@@#$(sed_escape "$(php85_only)")#g" \
        -e "s#@@APP_KEY@@#$(sed_escape "${APP_KEY:-}")#g" \
        -e "s#@@APP_URL@@#$(sed_escape "${APP_URL:-}")#g" \
        "$src" > "$dest.tmp"
    if grep -q '@@' "$dest.tmp"; then
        rm -f "$dest.tmp"
        die "Template $src still has unfilled placeholders."
    fi
    chmod "$mode" "$dest.tmp"
    mv "$dest.tmp" "$dest"
}

# conf_get KEY: read a value from the install state file.
conf_get() {
    local file
    file="$(p "$CONF_DIR/install.conf")"
    if [ -f "$file" ]; then
        grep "^$1=" "$file" | tail -n 1 | cut -d= -f2- || true
    fi
}

conf_set() {
    local file key=$1 value=$2
    file="$(p "$CONF_DIR/install.conf")"
    mkdir -p "$(dirname "$file")"
    touch "$file"
    grep -v "^$key=" "$file" > "$file.tmp" || true
    printf '%s=%s\n' "$key" "$value" >> "$file.tmp"
    mv "$file.tmp" "$file"
}

# The panel's own-domain site is written by the agent, only when the domain or its
# certificate changes. After an upgrade it is written again so that changes a new
# release makes to it (such as the terminal location) arrive. Best effort: the
# panel works without it.
refresh_panel_site() {
    if [ "$SYSTEM" != 1 ] || [ "$UPGRADE" != 1 ]; then return 0; fi
    as_panel commypanel:sync-panel-site >/dev/null 2>&1 \
        || warn "Could not refresh the panel's own nginx site. If the terminal does not open on the panel's domain, save the panel domain again under Welcome."
}

as_panel() {
    ( cd "$RELEASE_DIR" && runuser -u commypanel -- env LARAVEL_STORAGE_PATH="$DATA_DIR/storage" "$PHP_BIN" artisan "$@" )
}

# ---------------------------------------------------------------- preflight

unsupported() {
    if [ "${COMMYPANEL_ALLOW_UNSUPPORTED:-0}" = 1 ]; then
        warn "$1 This system is not supported; continuing because COMMYPANEL_ALLOW_UNSUPPORTED=1."
    else
        die "$1 CommyPanel supports Ubuntu 24.04 and 26.04, and Debian 13 or newer. (Set COMMYPANEL_ALLOW_UNSUPPORTED=1 to try anyway, at your own risk.)"
    fi
}

# os_field NAME: read one field of /etc/os-release. The file is sourced in a
# subshell because it defines names such as VERSION that must not leak into
# this script (it once overwrote the release version).
os_field() {
    ( . "${COMMYPANEL_OS_RELEASE:-/etc/os-release}" && eval "printf '%s' \"\${$1:-}\"" )
}

detect_os() {
    [ -r "${COMMYPANEL_OS_RELEASE:-/etc/os-release}" ] || die "Cannot read /etc/os-release."
    OS_ID="$(os_field ID)"
    OS_ID="${OS_ID:-unknown}"
    OS_VERSION_ID="$(os_field VERSION_ID)"
    OS_CODENAME="$(os_field UBUNTU_CODENAME)"
    if [ -z "$OS_CODENAME" ]; then OS_CODENAME="$(os_field VERSION_CODENAME)"; fi
    OS_NAME="$(os_field PRETTY_NAME)"
    case "$OS_ID" in
        ubuntu)
            case "$OS_VERSION_ID" in
                24.04|26.04) ;;
                *) unsupported "Ubuntu ${OS_VERSION_ID:-?} is not supported." ;;
            esac
            ;;
        debian)
            local major="${OS_VERSION_ID%%.*}"
            case "$major" in
                ""|*[!0-9]*) unsupported "Could not read the Debian version." ;;
                *) if [ "$major" -lt 13 ]; then unsupported "Debian $major is not supported (it ships PHP older than 8.3)."; fi ;;
            esac
            ;;
        *) unsupported "${OS_NAME:-This distribution} is not supported." ;;
    esac
}

detect_arch() {
    case "$(uname -m)" in
        x86_64|amd64) ARCH=amd64 ;;
        aarch64|arm64) ARCH=arm64 ;;
        *) die "Unsupported CPU architecture: $(uname -m)." ;;
    esac
}

port_owner() {
    # Prints the name of the process listening on TCP port $1, if any.
    ss -ltnpH "sport = :$1" 2>/dev/null | sed -n 's/.*users:(("\([^"]*\)".*/\1/p' | head -n 1
}

preflight() {
    if [ "$SYSTEM" != 1 ]; then
        ARCH="${COMMYPANEL_ARCH:-amd64}"
        return 0
    fi

    [ "$(id -u)" -eq 0 ] || die "Run this as root."
    [ -d /run/systemd/system ] || die "systemd is required."
    detect_os
    detect_arch

    case "$RELEASE_DIR" in
        "$PREFIX"/releases/*) ;;
        *) die "setup.sh must run from $PREFIX/releases/<version> (use install.sh)." ;;
    esac

    if [ "$UPGRADE" = 0 ]; then
        local owner
        for port in 80 "$PANEL_PORT"; do
            owner="$(port_owner "$port")"
            if [ -n "$owner" ] && [ "$owner" != "nginx" ]; then
                die "Port $port is already used by '$owner'. Stop it first (for example: systemctl disable --now $owner)."
            fi
        done
    fi

    local mem_kb
    mem_kb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)"
    if [ "${mem_kb:-0}" -lt 900000 ]; then
        warn "This server has less than 1 GB of RAM. Building apps may run out of memory."
    fi
}

# ----------------------------------------------------------------- packages

install_docker() {
    if command -v docker >/dev/null 2>&1; then
        log "Docker is already installed"
        return 0
    fi

    log "Installing Docker"
    local list=/etc/apt/sources.list.d/docker.list key=/etc/apt/keyrings/docker.asc
    install -m 0755 -d /etc/apt/keyrings
    if [ -n "$OS_CODENAME" ] \
        && curl -fsSL "https://download.docker.com/linux/$OS_ID/gpg" -o "$key" \
        && curl -fsI "https://download.docker.com/linux/$OS_ID/dists/$OS_CODENAME/Release" >/dev/null 2>&1; then
        chmod a+r "$key"
        printf 'deb [arch=%s signed-by=%s] https://download.docker.com/linux/%s %s stable\n' \
            "$(dpkg --print-architecture)" "$key" "$OS_ID" "$OS_CODENAME" > "$list"
        apt-get update -qq
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io
    else
        warn "Docker's own repository does not list '$OS_CODENAME' yet; using the distribution's docker.io package."
        rm -f "$list"
        apt-get install -y -qq docker.io
    fi
}

# The PHP packages that are installed, one per line. A pattern such as 'php*'
# given to apt would also name every uninstalled package that matches, and apt
# would report each one as skipped.
installed_php_packages() {
    dpkg-query -W -f='${db:Status-Abbrev} ${binary:Package}\n' 'php*' 2>/dev/null | awk '$1 == "ii" { print $2 }'
}

install_packages() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi

    log "Installing packages (nginx, PHP, Git, SQLite tools)"
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends \
        nginx php-cli php-fpm php-sqlite3 php-mbstring php-xml php-curl php-zip php-intl \
        git openssh-client curl ca-certificates gnupg sqlite3 openssl unzip iproute2 util-linux
    # Installing the php-cli and php-fpm meta-packages leaves an older php8.x-cli
    # and php8.x-fpm alone when the meta-packages are current. Bring every PHP
    # package on the server up to what the distribution offers: that is how
    # security fixes arrive (see installer/php-security.conf).
    local php_packages
    php_packages="$(installed_php_packages)"
    if [ -n "$php_packages" ]; then
        # shellcheck disable=SC2086
        apt-get install -y -qq --only-upgrade $php_packages
    fi
    if [ "$SKIP_DOCKER" = 0 ]; then install_docker; fi
}

detect_php() {
    if [ "$SYSTEM" != 1 ]; then
        PHP_BIN="${COMMYPANEL_PHP_BIN:-/usr/bin/php}"
        PHP_VER="${COMMYPANEL_PHP_VERSION:-8.4}"
        return 0
    fi

    PHP_BIN="$(command -v php)" || die "PHP was not installed."
    PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
        || die "PHP 8.3 or newer is required, but this server has PHP $PHP_VER."
    "$PHP_BIN" -r 'foreach (["intl", "mbstring", "pdo_sqlite", "curl", "xml", "zip", "pcntl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension: $e\n"); exit(1); } }' \
        || die "A required PHP extension is missing."
}

# Refuses a PHP without the fix for a critical flaw listed in
# installer/php-security.conf (the upgrade in install_packages has already pulled
# in whatever fix the distribution has published). An upgrade only warns: the
# server is no safer for refusing it, and the release carries pool hardening.
check_php_security() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi

    local version gaps advice
    version="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
    gaps="$(php_security_gaps "$RELEASE_DIR/installer/php-security.conf" "$version" "${PHP_CHANGELOG:-/usr/share/doc/php$PHP_VER-common/changelog.Debian.gz}" | tr '\n' ' ')"
    gaps="${gaps% }"

    if [ -z "$gaps" ]; then
        log "PHP $version has the security fixes this release checks for."
        return 0
    fi

    advice="PHP $version has no fix for $gaps (critical: remote code execution without logging in). Update it with: sudo apt-get update && sudo apt-get upgrade. If that changes nothing, your distribution has not published the fix yet."
    if [ "${COMMYPANEL_ALLOW_VULNERABLE_PHP:-0}" = 1 ]; then
        warn "$advice Continuing because COMMYPANEL_ALLOW_VULNERABLE_PHP=1."
    elif [ "${UPGRADE:-0}" = 1 ]; then
        warn "$advice The upgrade continues, but this server stays exposed until PHP is updated."
    else
        die "$advice Not installing on a vulnerable PHP. (Set COMMYPANEL_ALLOW_VULNERABLE_PHP=1 to install anyway, at your own risk.)"
    fi
}

# --------------------------------------------------------- users and folders

create_user_and_dirs() {
    if [ "$SYSTEM" = 1 ]; then
        getent group commypanel >/dev/null || groupadd --system commypanel
        id commypanel >/dev/null 2>&1 \
            || useradd --system --gid commypanel --home-dir "$DATA_DIR" --no-create-home --shell /usr/sbin/nologin commypanel
        PANEL_UID="$(id -u commypanel)"
    else
        PANEL_UID="${COMMYPANEL_PANEL_UID:-999}"
    fi

    log "Preparing directories"
    # Only the panel user: nginx no longer needs to reach anything in here (the ACME
    # challenge files live in the agent's own folder, /var/lib/commypanel-agent).
    mkd 0750 commypanel commypanel "$(p "$DATA_DIR")"
    mkd 0700 commypanel commypanel "$(p "$DATA_DIR/db")"
    mkd 0700 commypanel commypanel "$(p "$DATA_DIR/tmp")"
    mkd 0750 commypanel commypanel "$(p "$DATA_DIR/builds")"
    mkd 0750 commypanel commypanel "$(p "$DATA_DIR/bootstrap-cache")"
    mkd 0750 commypanel commypanel "$(p "$DATA_DIR/storage")"
    local sub
    for sub in logs framework/cache framework/sessions framework/views; do
        mkd 0750 commypanel commypanel "$(p "$DATA_DIR/storage/$sub")"
    done
    mkd 0750 root commypanel "$(p "$CONF_DIR")"
    mkd 0700 root root "$(p "$CONF_DIR/tls")"
    mkd 0755 root root "$(p "$NGINX_DIR/commypanel")"
    mkd 0755 root root "$(p "$PREFIX/releases")"
}

# ------------------------------------------------------------------ settings

detect_address() {
    SERVER_ADDR="${COMMYPANEL_ADDRESS:-}"
    if [ -z "$SERVER_ADDR" ] && command -v ip >/dev/null 2>&1; then
        SERVER_ADDR="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}' || true)"
    fi
    if [ -z "$SERVER_ADDR" ] && command -v hostname >/dev/null 2>&1; then
        SERVER_ADDR="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
    fi
    if [ -z "$SERVER_ADDR" ]; then SERVER_ADDR=localhost; fi
}

write_env() {
    local env_file
    env_file="$(p "$CONF_DIR/env")"

    if [ -f "$env_file" ]; then
        # Existing installation: keep every value, add any setting a newer release needs.
        local key line
        for line in \
            "COMMYPANEL_DATA_PATH=$DATA_DIR" \
            "LARAVEL_STORAGE_PATH=$DATA_DIR/storage" \
            "HOST_DRIVER=agent" \
            "AGENT_SOCKET=/run/commypanel/agent.sock"; do
            key="${line%%=*}"
            if ! grep -q "^$key=" "$env_file"; then printf '%s\n' "$line" >> "$env_file"; fi
        done
        return 0
    fi

    APP_KEY="base64:$(openssl rand -base64 32)"
    APP_URL="https://$SERVER_ADDR:$PANEL_PORT"
    render "$TEMPLATES/env.template" "$env_file" 0640
    if [ "$SYSTEM" = 1 ]; then chown root:commypanel "$env_file"; fi
    unset APP_KEY
}

ensure_panel_certificate() {
    local dir
    dir="$(p "$CONF_DIR/tls")"
    if [ -f "$dir/panel.crt" ] && [ -f "$dir/panel.key" ]; then return 0; fi

    if [ "$SYSTEM" != 1 ]; then
        : > "$dir/panel.crt"
        : > "$dir/panel.key"
        return 0
    fi

    log "Creating a self-signed certificate for the panel (replaced once you add a panel domain)"
    local san
    if printf '%s' "$SERVER_ADDR" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
        san="IP:$SERVER_ADDR,DNS:localhost"
    else
        san="DNS:$SERVER_ADDR,DNS:localhost"
    fi
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 3650 \
        -subj "/CN=CommyPanel" -addext "subjectAltName=$san" \
        -keyout "$dir/panel.key" -out "$dir/panel.crt" 2>/dev/null
    chmod 0600 "$dir/panel.key"
}

# ------------------------------------------------------------------ release

prepare_release() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi

    log "Preparing release $VERSION"
    chown -R root:root "$RELEASE_DIR"
    # Readable (and enterable) by everyone, writable by root only. The unpacked
    # tarball's top folder can arrive as 0700, which would lock out the panel user.
    chmod -R go+rX,go-w "$RELEASE_DIR"

    # The code stays read-only for the panel user; only these paths are writable.
    # The bootstrap cache is per release: a rollback must never see the caches of a newer version.
    install -d -m 0750 -o commypanel -g commypanel "$DATA_DIR/bootstrap-cache/$VERSION"
    rm -rf "${RELEASE_DIR:?}/storage" "${RELEASE_DIR:?}/bootstrap/cache"
    ln -sfn "$DATA_DIR/storage" "$RELEASE_DIR/storage"
    ln -sfn "$DATA_DIR/bootstrap-cache/$VERSION" "$RELEASE_DIR/bootstrap/cache"
    ln -sfn "$CONF_DIR/env" "$RELEASE_DIR/.env"

    local binary="$RELEASE_DIR/bin/commypanel-agent-linux-$ARCH"
    [ -f "$binary" ] || die "This release has no agent for $ARCH."
    cp "$binary" "$RELEASE_DIR/bin/commypanel-agent"
    chmod 0755 "$RELEASE_DIR/bin/commypanel-agent"
    rm -f "$RELEASE_DIR"/bin/commypanel-agent-linux-*
}

backup_databases() {
    if [ "$SYSTEM" != 1 ] || [ "$UPGRADE" = 0 ]; then return 0; fi
    if ! ls "$DATA_DIR"/db/*.sqlite >/dev/null 2>&1; then return 0; fi

    local stamp dest file
    stamp="$(date +%Y%m%d-%H%M%S)"
    dest="$DATA_DIR/backups/$stamp"
    log "Backing up the panel databases to $dest"
    install -d -m 0700 -o commypanel -g commypanel "$DATA_DIR/backups" "$dest"
    for file in "$DATA_DIR"/db/*.sqlite; do
        runuser -u commypanel -- sqlite3 "$file" ".backup '$dest/$(basename "$file")'"
    done

    # Keep the five newest backups.
    ls -1dt "$DATA_DIR"/backups/*/ 2>/dev/null | tail -n +6 | while IFS= read -r old; do
        rm -rf "${old:?}"
    done
}

migrate_and_cache() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi

    log "Migrating the panel databases"
    as_panel commypanel:migrate --force
    log "Caching configuration"
    as_panel optimize:clear >/dev/null
    as_panel optimize >/dev/null
}

# --------------------------------------------------------------- system config

# install_managed SRC DEST MODE: render a template, keeping a backup of what it replaces.
install_managed() {
    local src=$1 dest=$2 mode=$3
    if [ -f "$dest" ] && [ "$SYSTEM" = 1 ]; then cp -p "$dest" "$dest.commypanel-backup"; fi
    render "$src" "$dest" "$mode"
}

restore_managed() {
    local dest=$1
    if [ -f "$dest.commypanel-backup" ]; then
        mv "$dest.commypanel-backup" "$dest"
    else
        rm -f "$dest"
    fi
}

install_system_config() {
    log "Writing service and web server configuration"

    local unit_dir
    unit_dir="$(p /etc/systemd/system)"
    install_managed "$TEMPLATES/commypanel-agent.service" "$unit_dir/commypanel-agent.service" 0644
    install_managed "$TEMPLATES/commypanel-queue@.service" "$unit_dir/commypanel-queue@.service" 0644
    install_managed "$TEMPLATES/commypanel-scheduler.service" "$unit_dir/commypanel-scheduler.service" 0644

    local nginx_include nginx_panel fpm_pool
    nginx_include="$(p "$NGINX_DIR/conf.d/00-commypanel.conf")"
    nginx_panel="$(p "$NGINX_DIR/conf.d/10-commypanel-panel.conf")"
    fpm_pool="$(p "/etc/php/$PHP_VER/fpm/pool.d/commypanel.conf")"

    if [ "$SYSTEM" = 1 ] && ! grep -Rqs 'conf.d/\*.conf' "$NGINX_DIR/nginx.conf"; then
        die "nginx.conf does not include conf.d/*.conf, so CommyPanel cannot add its sites. Please add that include to the http block and run the installer again."
    fi

    install_managed "$TEMPLATES/nginx-include.conf" "$nginx_include" 0644
    install_managed "$TEMPLATES/nginx-panel.conf" "$nginx_panel" 0644
    install_managed "$TEMPLATES/php-fpm-pool.conf" "$fpm_pool" 0644

    # Our catch-all port-80 site (installed by the agent) would clash with the distribution's default site.
    if [ "$SYSTEM" = 1 ] && [ -e "$NGINX_DIR/sites-enabled/default" ]; then
        log "Disabling nginx's default site (CommyPanel serves its own)"
        rm -f "$NGINX_DIR/sites-enabled/default"
    fi

    if [ "$SYSTEM" = 1 ]; then
        if ! "php-fpm$PHP_VER" -t >/dev/null 2>&1; then
            restore_managed "$fpm_pool"
            die "The PHP-FPM configuration is invalid (php-fpm$PHP_VER -t failed); the change was undone."
        fi
        if ! nginx -t >/dev/null 2>&1; then
            nginx -t || true
            restore_managed "$nginx_panel"
            restore_managed "$nginx_include"
            die "nginx rejected the configuration (shown above); the change was undone."
        fi
    fi

    render "$TEMPLATES/commypanel-cli" "$(p /usr/local/bin/commypanel)" 0755
    sys systemctl daemon-reload
}

switch_release() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi
    ln -sfn "$RELEASE_DIR" "$PREFIX/current.new"
    mv -T "$PREFIX/current.new" "$PREFIX/current"
}

SERVICES="commypanel-agent commypanel-queue@1 commypanel-queue@2 commypanel-scheduler"

start_services() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi

    log "Starting services"
    if [ "$SKIP_DOCKER" = 0 ]; then systemctl enable --now docker; fi
    systemctl enable --now nginx "php$PHP_VER-fpm"
    systemctl reload "php$PHP_VER-fpm"
    systemctl reload nginx
    # shellcheck disable=SC2086
    systemctl enable $SERVICES >/dev/null 2>&1
    # shellcheck disable=SC2086
    systemctl restart $SERVICES
}

wait_until_healthy() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi
    local waited=0
    while [ "$waited" -lt 45 ]; do
        if [ -S /run/commypanel/agent.sock ] \
            && [ "$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$PANEL_PORT/up" 2>/dev/null || true)" = "200" ]; then
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done
    return 1
}

rollback() {
    local previous=$1
    warn "The new version did not start. Going back to the previous release."
    ln -sfn "$previous" "$PREFIX/current.new"
    mv -T "$PREFIX/current.new" "$PREFIX/current"
    # shellcheck disable=SC2086
    systemctl restart $SERVICES || true
    journalctl -u commypanel-agent -n 20 --no-pager >&2 || true
    die "Upgrade to $VERSION failed and $(basename "$previous") is running again. Database changes from the new release were kept; backups are in $DATA_DIR/backups."
}

prune_releases() {
    if [ "$SYSTEM" != 1 ]; then return 0; fi
    local current previous keep
    current="$(readlink -f "$PREFIX/current")"
    previous="${PREVIOUS_RELEASE:-}"
    ls -1dt "$PREFIX"/releases/*/ 2>/dev/null | tail -n +4 | while IFS= read -r dir; do
        dir="${dir%/}"
        keep=0
        if [ "$dir" = "$current" ] || [ "$dir" = "$previous" ]; then keep=1; fi
        if [ "$keep" = 0 ]; then
            rm -rf "${dir:?}" "${DATA_DIR:?}/bootstrap-cache/$(basename "$dir")"
        fi
    done

    local cache
    for cache in "$DATA_DIR"/bootstrap-cache/*; do
        if [ -d "$cache" ] && [ ! -d "$PREFIX/releases/$(basename "$cache")" ]; then
            rm -rf "${cache:?}"
        fi
    done
}

# ---------------------------------------------------------------------- main

# The stages of main(), as functions so each one can be a display step.

packages_and_php() {
    install_packages
    detect_php
    check_php_security
}

prepare_system() {
    create_user_and_dirs
    detect_address
    if [ "$UPGRADE" = 1 ]; then
        stored_addr="$(conf_get SERVER_ADDR)"
        if [ -n "$stored_addr" ]; then SERVER_ADDR="$stored_addr"; fi
    fi
    write_env
    conf_set PANEL_PORT "$PANEL_PORT"
    conf_set SERVER_ADDR "$SERVER_ADDR"
    ensure_panel_certificate
}

update_databases() {
    backup_databases
    migrate_and_cache
}

# First install: there is nothing to roll back to, so go live now. An upgrade
# switches the same way and rolls back in confirm_healthy if the panel is unwell.
go_live() {
    switch_release
    start_services
}

confirm_healthy() {
    if [ "$UPGRADE" = 0 ]; then
        wait_until_healthy || die "The panel did not become healthy. Check: systemctl status commypanel-agent nginx php$PHP_VER-fpm"
    elif ! wait_until_healthy; then
        rollback "${PREVIOUS_RELEASE:-$RELEASE_DIR}"
    fi
}


main() {
    ui_init
    if [ "$SYSTEM" = 1 ]; then
        mkdir -p "$(dirname "$LOG_FILE")"
        if [ "$UI_FANCY" = 1 ]; then
            exec >> "$LOG_FILE" 2>&1
        else
            exec > >(tee -a "$LOG_FILE") 2>&1
        fi
    fi

    VERSION="$(cat "$RELEASE_DIR/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
    if [ -z "$VERSION" ] && [ "$SYSTEM" != 1 ]; then VERSION="0.0.0-dev"; fi
    [ -n "$VERSION" ] || die "This release has no VERSION file."

    # It is an upgrade only if a release has gone live before. An environment file
    # without one is a first install that stopped half-way: resume it as a fresh
    # install (the existing settings and APP_KEY are kept).
    UPGRADE=0
    if [ -f "$(p "$CONF_DIR/env")" ] && { [ -L "$PREFIX/current" ] || [ "$SYSTEM" != 1 ]; }; then UPGRADE=1; fi
    if [ "$UPGRADE_FLAG" = 1 ] && [ "$UPGRADE" = 0 ] && [ "$SYSTEM" = 1 ]; then
        die "There is no existing installation to upgrade. Run the installer without --upgrade."
    fi
    if [ "$UPGRADE" = 1 ]; then
        stored_port="$(conf_get PANEL_PORT)"
        if [ -n "$stored_port" ]; then PANEL_PORT="$stored_port"; fi
        banner "Upgrading to $VERSION"
    else
        banner "Installing $VERSION"
    fi

    step "Checking this server" preflight
    step "Installing packages (nginx, PHP, Git, Docker)" packages_and_php

    PREVIOUS_RELEASE=""
    if [ "$SYSTEM" = 1 ] && [ -L "$PREFIX/current" ]; then PREVIOUS_RELEASE="$(readlink -f "$PREFIX/current")"; fi

    step "Creating folders and settings" prepare_system
    step "Unpacking the release" prepare_release
    step "Backing up and migrating the databases" update_databases
    step "Configuring nginx and services" install_system_config
    step "Starting CommyPanel" go_live
    step "Waiting for the panel to answer" confirm_healthy
    refresh_panel_site
    prune_releases

    if [ "$SYSTEM" != 1 ]; then
        log "Rendered configuration below ${ROOT:-/}"
        return 0
    fi

    # setup-url only succeeds while no admin account exists.
    local url
    url="$(as_panel commypanel:setup-url 2>/dev/null | grep -Eo 'https?://[^[:space:]]+' | head -n 1 || true)"

    if [ "$UPGRADE" = 1 ] && [ -z "$url" ]; then
        say '%s%s CommyPanel is now version %s.%s\n\n' "$C_GREEN" "$MARK_OK" "$VERSION" "$C_RESET"
        return 0
    fi

    say '\n%s%s CommyPanel %s is installed.%s\n\n' "$C_GREEN" "$MARK_OK" "$VERSION" "$C_RESET"
    say '  Create your admin account (works once, expires in 1 hour):\n\n    %s%s%s\n\n' "$C_BOLD" "${url:-run: sudo commypanel setup-url}" "$C_RESET"
    say '  Your browser will warn about the panel certificate: it is self-signed until you add a panel domain.\n\n'
    say '  Open these ports in your firewall: 80, 443 and %s.\n' "$PANEL_PORT"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then
        say '  ufw is active here, so run:  sudo ufw allow 80,443,%s/tcp\n' "$PANEL_PORT"
    fi
    say '\n  Manage the panel:  sudo commypanel help\n\n'
}

# Tests source this file to call single functions without running an install.
if [ "${COMMYPANEL_SOURCE_ONLY:-0}" != 1 ]; then
    main
fi
