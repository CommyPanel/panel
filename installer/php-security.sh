#!/usr/bin/env bash
# Checks the installed PHP against installer/php-security.conf. Sourced by
# setup.sh; kept free of anything newer than bash 3.2 so it can be tested on a Mac.

# php_version_ge A B: succeeds when version A is the same as or newer than B.
# Versions are numbers separated by dots (8.5.12); anything after the numbers
# (such as -dev or RC1) is ignored. Numbers are compared as numbers, so 8.5.9 is
# older than 8.5.12.
php_version_ge() {
    local a=${1%%[!0-9.]*} b=${2%%[!0-9.]*}
    local IFS=.
    # shellcheck disable=SC2206
    local -a x=($a) y=($b)
    local i n=${#x[@]} xi yi
    if [ "${#y[@]}" -gt "$n" ]; then n=${#y[@]}; fi
    for ((i = 0; i < n; i++)); do
        xi=$((10#${x[i]:-0}))
        yi=$((10#${y[i]:-0}))
        if [ "$xi" -gt "$yi" ]; then return 0; fi
        if [ "$xi" -lt "$yi" ]; then return 1; fi
    done
    return 0
}

# php_package_version PACKAGE: the installed version of a Debian package, or nothing.
php_package_version() {
    dpkg-query -W -f='${Version}' "$1" 2>/dev/null || true
}

# php_package_fixed SPECS: succeeds when any "deb:PACKAGE=VERSION" in SPECS is
# installed at that version or newer (compared the way dpkg compares versions).
# This is how Ubuntu's fixed builds are recognised: their upstream version stays
# 8.5.4, and only the package revision (8.5.4-0ubuntu1.5) tells.
php_package_fixed() {
    local spec package required installed
    for spec in $1; do
        spec=${spec#deb:}
        package=${spec%%=*}
        required=${spec#*=}
        installed="$(php_package_version "$package")"
        if [ -n "$installed" ] && dpkg --compare-versions "$installed" ge "$required" 2>/dev/null; then
            return 0
        fi
    done
    return 1
}

# php_changelog_text FILE: the text of a changelog, plain or gzipped.
php_changelog_text() {
    case "$1" in
        *.gz) gzip -dc "$1" 2>/dev/null ;;
        *) cat "$1" 2>/dev/null ;;
    esac
}

# php_security_gaps CONF VERSION CHANGELOG: prints, one per line, the flaws in
# CONF that this PHP is not known to have fixed. VERSION is the full PHP
# version (8.5.4); CHANGELOG is the package changelog, which may not exist.
# A flaw counts as fixed by the upstream version, by the CVE in the changelog,
# or by a distribution package build that is at least as new as the fixed one.
php_security_gaps() {
    local conf=$1 version=${2%%[!0-9.]*} changelog=$3
    local branch="${version%.*}" cve rest pair minimum debs

    if [ ! -r "$conf" ]; then return 0; fi

    while read -r cve rest; do
        case "$cve" in ''|'#'*) continue ;; esac

        minimum="" debs=""
        for pair in $rest; do
            case "$pair" in
                "$branch="*) minimum="${pair#*=}" ;;
                # Only the package of the PHP branch that is running: a fixed php8.3 says nothing about php8.5.
                "deb:php$branch-"*=*) debs="$debs $pair" ;;
            esac
        done
        if [ -z "$minimum" ]; then continue; fi

        if php_version_ge "$version" "$minimum"; then continue; fi
        # The whole id, not part of a longer one: CVE-2026-1096180 is not CVE-2026-109618.
        if [ -r "$changelog" ] && php_changelog_text "$changelog" | grep -Eq "(^|[^0-9A-Za-z-])${cve}([^0-9]|\$)"; then continue; fi

        if php_package_fixed "$debs"; then continue; fi

        printf '%s\n' "$cve"
    done < "$conf"
}
