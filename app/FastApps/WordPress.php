<?php

namespace App\FastApps;

use App\Enums\DatabaseEngine;
use App\Enums\Stack;
use App\Models\App;
use Illuminate\Support\Str;

/**
 * WordPress on the official image (Apache and PHP in one container), with its database on one of
 * CommyPanel's MariaDB or MySQL servers and `wp-content` (themes, plugins, uploads) in a volume.
 *
 * Choices that matter:
 * - The core lives in the image: it is updated by deploying again, never inside the running
 *   container (that copy would be lost at the next deployment). Plugins and themes are in the volume
 *   and are installed and updated from WordPress as usual.
 * - The address follows the domain the site is opened on (`WP_HOME` from the request), so domains can
 *   be added or changed later without repairing the database.
 * - The first user and site title are installed by WP-CLI at the first start, from variables that
 *   are removed once the app went live. The salts are made here once, so sessions survive deployments.
 */
final class WordPress implements FastApp
{
    /** The base image: the newest 6.x on PHP 8.3 with Apache. Deploying again pulls it anew. */
    public const IMAGE = 'wordpress:6-php8.3-apache';

    private const SALTS = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

    public function id(): string
    {
        return 'wordpress';
    }

    public function name(): string
    {
        return 'WordPress';
    }

    public function description(): string
    {
        return __('The most used website and blog software. Installed for you, with its own database and room for themes and plugins.');
    }

    public function stack(): Stack
    {
        return Stack::Wordpress;
    }

    public function engines(): array
    {
        return [DatabaseEngine::Mariadb, DatabaseEngine::Mysql];
    }

    public function fields(): array
    {
        return [
            new Field('site_title', __('Site title'), 'text', __('The name of the website. You can change it in WordPress later.'), ['required', 'string', 'max:100', 'regex:/^[^\r\n]+$/']),
            new Field('admin_user', __('Admin username'), 'text', __('Letters, digits and . _ - @. Avoid "admin": it is the first name attackers try.'), ['required', 'string', 'regex:/^[A-Za-z0-9._@-]{3,60}$/']),
            new Field('admin_email', __('Admin email'), 'email', __('Where WordPress sends password resets and notices.'), ['required', 'string', 'email', 'max:190']),
            new Field('admin_password', __('Admin password'), 'password', __('At least 12 characters. You can change it in WordPress later.'), ['required', 'string', 'min:12', 'max:100', 'regex:/^[^\r\n]+$/']),
        ];
    }

    public function containerPort(): int
    {
        return 80;
    }

    public function healthPath(): string
    {
        return '/wp-login.php';
    }

    public function adminPath(): string
    {
        return '/wp-admin/';
    }

    public function volumePaths(): array
    {
        return ['/var/www/html/wp-content'];
    }

    public function databasePrefix(): string
    {
        // The official image reads WORDPRESS_DB_HOST, _USER, _PASSWORD and _NAME, which is what this prefix gives.
        return 'WORDPRESS_DB';
    }

    public function memoryLimitMb(): int
    {
        return 512;
    }

    public function nginxCustom(): string
    {
        return "# Themes and media are uploaded through WordPress.\nclient_max_body_size 128m;";
    }

    public function environment(array $settings): array
    {
        $env = [
            'WP_SITE_TITLE' => $settings['site_title'],
            'WP_ADMIN_USER' => $settings['admin_user'],
            'WP_ADMIN_EMAIL' => $settings['admin_email'],
            'WP_ADMIN_PASSWORD' => $settings['admin_password'],
        ];

        // Made once: new ones at every deployment would sign everybody out.
        foreach (self::SALTS as $salt) {
            $env["WORDPRESS_{$salt}"] = Str::random(64);
        }

        return $env;
    }

    public function oneTimeVariables(): array
    {
        return ['WP_ADMIN_PASSWORD'];
    }

    public function release(): string
    {
        return 'WordPress 6';
    }

    public function files(App $app): array
    {
        $image = self::IMAGE;

        return [
            'Dockerfile' => <<<DOCKERFILE
            # Written by CommyPanel for this app. It is made again at every deployment.
            FROM {$image}

            # WP-CLI installs the site the first time the container starts.
            COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp

            COPY commypanel.ini /usr/local/etc/php/conf.d/zz-commypanel.ini
            COPY commypanel.conf /etc/apache2/conf-available/commypanel.conf
            COPY commypanel-config.php /usr/local/etc/commypanel-config.php
            COPY commypanel-install.sh commypanel-entrypoint.sh /usr/local/bin/
            RUN a2enconf commypanel \\
                && chmod +x /usr/local/bin/commypanel-install.sh /usr/local/bin/commypanel-entrypoint.sh

            ENV WORDPRESS_CONFIG_EXTRA="require '/usr/local/etc/commypanel-config.php';"

            ENTRYPOINT ["commypanel-entrypoint.sh"]
            CMD ["apache2-foreground"]

            DOCKERFILE,

            'commypanel-entrypoint.sh' => <<<'SH'
            #!/bin/sh
            # Starts the installer in the background (it waits for wp-config.php and the database),
            # then hands over to the official entrypoint, which prepares the files and starts Apache.
            /usr/local/bin/commypanel-install.sh >> /proc/1/fd/1 2>&1 &
            exec docker-entrypoint.sh "$@"

            SH,

            'commypanel-install.sh' => <<<'SH'
            #!/bin/bash
            # Installs WordPress the first time the container starts, from the answers given when the app
            # was created. It runs at every start and does nothing once WordPress is installed.
            set -u
            cd /var/www/html || exit 0

            log() { echo "[commypanel] $*"; }
            wpc() { runuser -u www-data -- wp --path=/var/www/html "$@"; }

            # Without the answers (they are removed after the first start) there is nothing to do.
            if [ -z "${WP_ADMIN_PASSWORD:-}" ] || [ -z "${WP_ADMIN_USER:-}" ] || [ -z "${WP_ADMIN_EMAIL:-}" ]; then
                exit 0
            fi

            for _ in $(seq 1 120); do
                [ -f wp-config.php ] && break
                sleep 1
            done
            [ -f wp-config.php ] || { log "wp-config.php did not appear, not installing"; exit 0; }

            ready=0
            for _ in $(seq 1 90); do
                if php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")) ? 0 : 1);'; then
                    ready=1
                    break
                fi
                sleep 2
            done
            [ "$ready" = 1 ] || { log "the database did not answer, open the site to use WordPress's own installer"; exit 0; }

            if wpc core is-installed >/dev/null 2>&1; then
                exit 0
            fi

            log "installing WordPress"
            if wpc core install --url=http://localhost --title="${WP_SITE_TITLE:-WordPress}" \
                --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" \
                --admin_email="$WP_ADMIN_EMAIL" --skip-email >/dev/null; then
                wpc rewrite structure '/%postname%/' >/dev/null 2>&1 || true
                log "WordPress is installed"
            else
                log "the installation did not finish, open the site to use WordPress's own installer"
            fi

            SH,

            'commypanel-config.php' => <<<'PHP'
            <?php
            // Loaded from wp-config.php. Written by CommyPanel.

            // The address follows the domain the site is opened on (nginx only sends the domains connected
            // to this app), so a domain can be added or changed later without repairing the database.
            if (isset($_SERVER['HTTP_HOST']) && preg_match('/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/', $_SERVER['HTTP_HOST'])) {
                $commypanel_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                define('WP_HOME', $commypanel_scheme . '://' . $_SERVER['HTTP_HOST']);
                define('WP_SITEURL', $commypanel_scheme . '://' . $_SERVER['HTTP_HOST']);
            }

            // The core is part of the image: WordPress is updated by deploying the app again. Plugins and themes
            // live in wp-content and are installed and updated from WordPress as usual.
            define('WP_AUTO_UPDATE_CORE', false);
            // The built-in file editor would let a stolen admin session change code.
            define('DISALLOW_FILE_EDIT', true);

            PHP,

            'commypanel.ini' => <<<'INI'
            ; Written by CommyPanel.
            upload_max_filesize = 128M
            post_max_size = 128M
            memory_limit = 256M
            max_execution_time = 120
            max_input_vars = 3000
            expose_php = Off

            INI,

            'commypanel.conf' => <<<'CONF'
            # Written by CommyPanel.
            ServerTokens Prod
            ServerSignature Off

            <Directory /var/www/html>
                AllowOverride All

                # Pretty links work without WordPress writing .htaccess: that file lives in the container,
                # which is replaced at every deployment.
                <IfModule mod_rewrite.c>
                    RewriteEngine On
                    RewriteBase /
                    RewriteRule ^index\.php$ - [L]
                    RewriteCond %{REQUEST_FILENAME} !-f
                    RewriteCond %{REQUEST_FILENAME} !-d
                    RewriteRule . /index.php [L]
                </IfModule>
            </Directory>

            # Few workers, so the container stays inside its memory limit under load.
            <IfModule mpm_prefork_module>
                StartServers 2
                MinSpareServers 2
                MaxSpareServers 5
                MaxRequestWorkers 20
                MaxConnectionsPerChild 500
            </IfModule>

            CONF,
        ];
    }
}
