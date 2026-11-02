# Installing CommyPanel

CommyPanel is installed on the server it manages. It controls Docker and nginx on that machine, so install it on a server you are happy to dedicate to your apps.

## Requirements

- A fresh or lightly used server running **Ubuntu 24.04**, **Ubuntu 26.04** or **Debian 13** (or newer), with systemd. Other systems are refused. Debian 12 is not supported because it ships PHP 8.2 and CommyPanel needs 8.3 or newer.
- `amd64` or `arm64`.
- Root access, and at least 1 GB of RAM (more if you build large apps on the server).
- Ports **80** and **443** free for your apps, and one more for the panel (**8443** by default).

## Install

```bash
curl -fsSL https://github.com/emrecanful/commypanel/releases/latest/download/install.sh | sudo bash
```

The installer downloads the release, checks its SHA-256 checksum and stops if it does not match, then installs everything and prints a **one-time setup link**. Open it, create your admin account, and you are done. The link works once and expires after an hour; run `sudo commypanel setup-url` for a new one.

Your browser will warn about the panel's certificate. It is self-signed until you give the panel a domain of its own.

Options: `--panel-port N` to choose another port, `--no-docker` if you manage Docker yourself, `--version X` to install a specific version, `--yes` to skip the question before an upgrade, `--verbose` to see everything the installer does instead of the progress display.

## What it installs

| What                                                                                                                        | Where                                                                                  |
| --------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| nginx, PHP (from your distribution), Git, SQLite tools, Docker (Docker's own repository, or the distribution's `docker.io`) | installed with `apt`                                                                   |
| The panel code, one folder per release                                                                                      | `/opt/commypanel/releases/<version>`, `/opt/commypanel/current` points at the live one |
| Panel data: databases, certificates, builds, logs                                                                           | `/var/lib/commypanel`                                                                  |
| Settings and the panel's TLS certificate                                                                                    | `/etc/commypanel`                                                                      |
| nginx sites for your apps (written by the agent)                                                                            | `/etc/nginx/commypanel`                                                                |
| The `commypanel` command                                                                                                    | `/usr/local/bin/commypanel`                                                            |

It creates a `commypanel` system user for the web app and its workers, and these services:

- `commypanel-agent`: the only part that runs as root. It talks to Docker, nginx and Let's Encrypt on the panel's behalf, over a local socket that only the `commypanel` user can use.
- `commypanel-queue@1`, `commypanel-queue@2`: run deployments and certificate requests.
- `commypanel-scheduler`: re-checks domains and renews certificates.

The panel's PHP code is owned by root and read-only to the panel user. PHP comes from your distribution's packages, so security fixes arrive through normal `apt upgrade`.

nginx's default site is disabled, because CommyPanel installs its own catch-all site for certificate validation.

## Firewall

CommyPanel does not change your firewall. Open ports 80, 443 and the panel port, for example with ufw:

```bash
sudo ufw allow 80,443,8443/tcp
```

## Upgrading

```bash
sudo commypanel upgrade
```

An upgrade downloads and verifies the new release, backs up the panel databases (the five newest backups are kept in `/var/lib/commypanel/backups`), migrates them, switches over and restarts the services. If the new version does not come up healthy, the previous release is started again. Running the installer again does the same thing.

Before it changes anything, it downloads the release, checks it, and asks `Continue with the upgrade? [Y/n]` (Enter means yes). The question says that:

- **the panel is unavailable for a short while** while its services restart and it switches to the new version;
- **your apps stay online**: Docker and your containers are not restarted and nginx is reloaded gracefully. New deployments cannot start meanwhile, and a build in progress is interrupted;
- **a failed upgrade goes back** to the version you had. Database changes the new version already made are kept, and the databases are backed up first.

Answer `n` and nothing is changed. In scripts, add `--yes` (`sudo commypanel upgrade --yes`) to skip the question. Without a terminal the upgrade also goes ahead, with a notice.

## PHP security updates

CommyPanel runs on the PHP that your distribution provides, so PHP security fixes come from `apt`, not from CommyPanel. Ubuntu and Debian ship fixes without changing PHP's version number, so a patched server can still say `PHP 8.5.4` in `php -v`; what matters is the package build.

- **The installer updates PHP.** It brings every PHP package that is installed up to what the distribution offers, instead of only installing the `php-cli` and `php-fpm` meta-packages, which would leave an older `php8.x-fpm` in place.
- **The installer checks that PHP has the fix** for the critical flaws listed in `installer/php-security.conf`. A PHP counts as fixed when it is at least the first upstream release with the fix, or when the package's changelog names the CVE, or when the installed package is at least the distribution's fixed build (Ubuntu keeps the upstream version `8.5.4`; only the package revision, `8.5.4-0ubuntu1.5`, shows the fix).
- **A fresh install on a PHP without the fix is refused**, with the command to update it. If your distribution has not published the fix yet, wait for it, or set `COMMYPANEL_ALLOW_VULNERABLE_PHP=1` to install anyway, at your own risk. An **upgrade** only warns and goes on, because refusing would leave the server exposed just the same and the release carries extra hardening.
- **The panel's own PHP pool is hardened** against the two request paths named for PHP2Shell (`session.upload_progress.enabled=0`, and `fatal_error_backtraces=0` on PHP 8.5). The panel uses neither. This is a precaution, not a substitute for the update: PHP's own release notice says there is no complete mitigation.

The current entry is **PHP2Shell** (CVE-2026-109618 and CVE-2026-109619, unauthenticated remote code execution, exploited in the wild). Fixed upstream in PHP 8.3.36, 8.4.27 and 8.5.12; in the distributions as Debian 13 `8.4.27-1~deb13u1`, Ubuntu 26.04 `8.5.4-0ubuntu1.5` and Ubuntu 24.04 `8.3.6-0ubuntu0.24.04.13`.

To check a server by hand:

```bash
zcat /usr/share/doc/php8.5-common/changelog.Debian.gz | grep -c CVE-2026-109618   # use your PHP version; 0 means no fix
sudo apt-get update && sudo apt-get upgrade
sudo systemctl restart php8.5-fpm commypanel-queue@1 commypanel-queue@2 commypanel-scheduler
```

## Update notifications

When someone signs in, CommyPanel looks in the background for a newer release. If there is one, an **Update** badge appears in the top bar for people who can see the server, with a tooltip, and it leads to **Settings > Updates**. That page explains why to upgrade, the steps, what happens to your server while it upgrades, and shows the release notes of every release you have missed, pulled from GitHub.

- **What is sent.** CommyPanel asks GitHub for this project's list of releases, at most once every 6 hours (and again 30 minutes after a failure). GitHub sees the server's address and the CommyPanel version number in the request. Nothing else is sent, and nothing that identifies your apps, domains or users.
- **What is shown.** Only stable releases. Pre-releases and drafts are never offered. Release notes are rendered without raw HTML, scripts or remote images.
- **Switching it off.** The page has a switch for the automatic check (a manual **Check now** still works, for people who can manage the server). To stop every check, set `COMMYPANEL_UPDATE_CHECK=false` in `/etc/commypanel/env`; then nothing is ever sent, not even by hand.
- **Development checkouts** have no version and never check.

The page does not upgrade anything: you still run `sudo commypanel upgrade` on the server.

## Backing up and restoring the panel

The panel's own database (apps, domains, users, the audit log, database servers and their encrypted secrets) is backed up every night at 03:30, and the newest 7 are kept in `/var/lib/commypanel/backups/panel`. **Settings > Backups** lists them, takes one now, and downloads or deletes one (downloading asks for your password and is audited). `sudo commypanel backup` takes one from the command line.

A backup does **not** hold the encryption key, `APP_KEY` in `/etc/commypanel/env`: without it the encrypted values in a restored database (environment variables, tokens, database passwords) cannot be read. Keep a copy of the key somewhere safe, apart from the backups. It also does not hold files: the files in apps' volumes, the data of database servers and built images. Copy backups off the server, since one on the same disk is lost with it.

To restore, on the server: `sudo commypanel restore FILE`, with the name of a backup in that folder or the path of a file you downloaded. The panel's queue workers and scheduler stop, the file is checked (it must be a healthy CommyPanel database), the current database is kept as a backup marked `-before`, the database is replaced, and everything starts again. To restore onto a new server, install CommyPanel there first and put the old `APP_KEY` into its `/etc/commypanel/env` before restoring.

## Command line

```
sudo commypanel setup-url          one-time link to create the first admin
sudo commypanel admin:reset EMAIL  new random password for an admin, and turns off its 2FA
sudo commypanel status             version, services and panel address
sudo commypanel logs               follow the logs
sudo commypanel upgrade            upgrade
sudo commypanel backup             back up the panel's database now
sudo commypanel restore FILE       replace the panel's database with a backup
```

If you lose access to your admin account, `admin:reset` is the way back in.

## Uninstalling

There is no uninstall command yet. Your apps run as Docker containers and keep running if the panel is removed. To remove CommyPanel by hand, stop and disable the `commypanel-*` services, delete their unit files, `/etc/nginx/conf.d/*-commypanel*.conf` and `/etc/nginx/commypanel`, then `/opt/commypanel`. Keep `/var/lib/commypanel` if you may reinstall.

## Troubleshooting

- The full installer log is in `/var/log/commypanel-install.log`. If a step fails, the installer prints the end of it.
- `sudo commypanel status` shows which service is down; `sudo journalctl -u commypanel-agent -n 50` shows why.
- "Port 80 is already used by ...": another web server is running. Stop and disable it first.
