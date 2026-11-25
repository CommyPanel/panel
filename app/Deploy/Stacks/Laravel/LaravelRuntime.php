<?php

namespace App\Deploy\Stacks\Laravel;

/**
 * The files that make a Laravel app run in its container: nginx and PHP-FPM settings, PHP's settings and
 * the script that gets the container ready and runs both servers. They are put next to the Dockerfile in
 * `.commypanel/` and never contain a secret.
 *
 * The image is the conventional layout: nginx in front of PHP-FPM, both in the one container, both run
 * by the non-root user www-data (nginx listens on $PORT, everything else lives under /tmp). The nginx
 * configuration does what a careful hand-written Laravel site does:
 *  - Only /index.php is passed to PHP-FPM. Any other .php path (a file an app lets people upload into
 *    storage is reachable through public/storage) and any dotfile except /.well-known/ answers 404.
 *  - A request that CommyPanel's nginx forwarded as HTTPS (X-Forwarded-Proto) is shown to PHP as HTTPS,
 *    so url(), asset() and redirects are https:// without the app having to trust proxies, and the
 *    visitor's address is the one the outer nginx saw (X-Real-IP, which it sets itself). Both headers
 *    are believed ONLY when the connection comes from a private address: the container's port is
 *    published on the server's loopback only, and a header from anywhere else is ignored.
 *  - The environment reaches PHP (`clear_env = no`), PHP's and nginx's errors go to stderr, and a stop
 *    signal ends both servers gracefully.
 *
 * Settings from the environment (all optional): PORT, NGINX_CLIENT_MAX_BODY_SIZE (100m),
 * PHP_FPM_PM_MAX_CHILDREN (10), PHP_FPM_PM_START_SERVERS, PHP_FPM_PM_MIN_SPARE_SERVERS,
 * PHP_FPM_PM_MAX_SPARE_SERVERS, PHP_FPM_PM_MAX_REQUESTS (500), PHP_FPM_PROCESS_CONTROL_TIMEOUT (8s: how long
 * a request may finish after a stop signal), PHP_MEMORY_LIMIT (256M),
 * PHP_UPLOAD_MAX_FILESIZE and PHP_POST_MAX_SIZE (100M), COMMYPANEL_OPTIMIZE (false skips `php artisan optimize`).
 */
final class LaravelRuntime
{
    /** The official image the app is built on: the PHP minor is pinned, the patch release follows. */
    public const DEBIAN = 'bookworm';

    /** The tool that installs PHP extensions (https://github.com/mlocati/docker-php-extension-installer). */
    public const EXTENSION_INSTALLER = 'mlocati/php-extension-installer:2.13.4';

    /**
     * @return array<string, string> path in the build context => contents
     */
    public static function files(): array
    {
        return [
            '.commypanel/nginx.conf' => self::nginx(),
            '.commypanel/php-fpm.conf' => self::fpm(),
            '.commypanel/php.ini' => self::phpIni(),
            '.commypanel/entrypoint.sh' => self::entrypoint(),
        ];
    }

    public static function image(string $php): string
    {
        return 'php:'.$php.'-fpm-'.self::DEBIAN;
    }

    public static function nginx(): string
    {
        return <<<'NGINX'
        # Written by CommyPanel. nginx runs as www-data; every path it writes is under /tmp.
        worker_processes auto;
        pid /tmp/nginx.pid;
        error_log stderr warn;

        events {
        	worker_connections 1024;
        }

        http {
        	include /etc/nginx/mime.types;
        	default_type application/octet-stream;
        	server_tokens off;
        	sendfile on;
        	keepalive_timeout 65;
        	access_log /dev/stdout;

        	client_body_temp_path /tmp/nginx/client_body;
        	fastcgi_temp_path /tmp/nginx/fastcgi;
        	proxy_temp_path /tmp/nginx/proxy;
        	uwsgi_temp_path /tmp/nginx/uwsgi;
        	scgi_temp_path /tmp/nginx/scgi;

        	gzip on;
        	gzip_vary on;
        	gzip_min_length 1024;
        	gzip_types text/plain text/css text/xml application/json application/javascript text/javascript application/xml image/svg+xml;

        	# Who may say what the scheme and the client address are: only a connection from a private address
        	# (CommyPanel's nginx on the same server). $realip_remote_addr is the real peer, whatever the headers say.
        	set_real_ip_from 127.0.0.0/8;
        	set_real_ip_from 10.0.0.0/8;
        	set_real_ip_from 172.16.0.0/12;
        	set_real_ip_from 192.168.0.0/16;
        	set_real_ip_from ::1/128;
        	set_real_ip_from fc00::/7;
        	real_ip_header X-Real-IP;

        	geo $realip_remote_addr $commypanel_private {
        		default 0;
        		127.0.0.0/8 1;
        		10.0.0.0/8 1;
        		172.16.0.0/12 1;
        		192.168.0.0/16 1;
        		::1/128 1;
        		fc00::/7 1;
        	}
        	map "$commypanel_private:$http_x_forwarded_proto" $commypanel_https {
        		default off;
        		"1:https" on;
        	}

        	server {
        		# listen and client_max_body_size, written from the environment when the container starts.
        		include /tmp/nginx/server.conf;

        		root /app/public;
        		index index.php;
        		charset utf-8;

        		# Only index.php runs PHP, as in the usual nginx setup for Laravel.
        		location = /index.php {
        			include /etc/nginx/fastcgi_params;
        			fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        			fastcgi_param HTTPS $commypanel_https;
        			fastcgi_pass unix:/tmp/php-fpm.sock;
        			fastcgi_read_timeout 120s;
        			fastcgi_buffer_size 16k;
        			fastcgi_buffers 16 16k;
        		}

        		location ~ \.php(/|$) {
        			return 404;
        		}

        		location ~ /\.(?!well-known(/|$)) {
        			return 404;
        		}

        		location /build/ {
        			expires 1y;
        			add_header Cache-Control "public, immutable";
        			try_files $uri =404;
        		}

        		location / {
        			try_files $uri $uri/ /index.php?$query_string;
        		}
        	}
        }

        NGINX;
    }

    public static function fpm(): string
    {
        return <<<'FPM'
        ; Written by CommyPanel. PHP-FPM runs as www-data and listens on a unix socket under /tmp.
        [global]
        pid = /tmp/php-fpm.pid
        error_log = /proc/self/fd/2
        daemonize = no
        ; How long workers get to finish their request when PHP-FPM is told to stop. With 0 it cuts them off at once.
        process_control_timeout = ${PHP_FPM_PROCESS_CONTROL_TIMEOUT}

        [www]
        listen = /tmp/php-fpm.sock
        listen.mode = 0660

        ; The app's environment (its variables, its secrets) must reach PHP.
        clear_env = no
        catch_workers_output = yes
        decorate_workers_output = no

        pm = dynamic
        pm.max_children = ${PHP_FPM_PM_MAX_CHILDREN}
        pm.start_servers = ${PHP_FPM_PM_START_SERVERS}
        pm.min_spare_servers = ${PHP_FPM_PM_MIN_SPARE_SERVERS}
        pm.max_spare_servers = ${PHP_FPM_PM_MAX_SPARE_SERVERS}
        pm.max_requests = ${PHP_FPM_PM_MAX_REQUESTS}

        php_admin_value[memory_limit] = ${PHP_MEMORY_LIMIT}
        php_admin_value[upload_max_filesize] = ${PHP_UPLOAD_MAX_FILESIZE}
        php_admin_value[post_max_size] = ${PHP_POST_MAX_SIZE}

        FPM;
    }

    public static function phpIni(): string
    {
        return <<<'INI'
        ; Settings CommyPanel gives every Laravel app, on top of PHP's production php.ini.
        memory_limit = 256M
        upload_max_filesize = 100M
        post_max_size = 100M
        expose_php = Off
        display_errors = Off
        log_errors = On
        opcache.enable = 1
        opcache.enable_cli = 0
        opcache.memory_consumption = 192
        opcache.max_accelerated_files = 20000
        opcache.validate_timestamps = 0

        INI;
    }

    public static function entrypoint(): string
    {
        return <<<'SH'
        #!/bin/bash
        # Gets the container ready, then either runs the command it was given (a queue worker, a release script,
        # php artisan ...) or, with no command, runs the web server: nginx and PHP-FPM together.
        set -e
        cd /app

        # A storage folder kept in a volume starts empty and hides the one the image made.
        mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache

        if [ ! -e public/storage ] && [ ! -L public/storage ]; then
        	php artisan storage:link >/dev/null 2>&1 || true
        fi

        # Caches config, routes, views and events with the environment this container really has.
        if [ "${COMMYPANEL_OPTIMIZE:-true}" != "false" ]; then
        	if ! php artisan optimize >/dev/null 2>&1; then
        		echo "commypanel: php artisan optimize failed; starting without the caches" >&2
        		php artisan optimize:clear >/dev/null 2>&1 || true
        	fi
        fi

        # Any other command runs as it is, with the same preparation.
        if [ "$#" -gt 0 ]; then
        	exec "$@"
        fi

        # nginx's settings that depend on the environment.
        mkdir -p /tmp/nginx
        body_size="${NGINX_CLIENT_MAX_BODY_SIZE:-100m}"
        if ! printf '%s' "$body_size" | grep -Eq '^[0-9]+[kKmMgG]?$'; then
        	echo "commypanel: NGINX_CLIENT_MAX_BODY_SIZE must be a size such as 100m; using 100m" >&2
        	body_size=100m
        fi
        port="${PORT:-8080}"
        printf 'listen %s default_server;\nclient_max_body_size %s;\n' "$port" "$body_size" > /tmp/nginx/server.conf

        # PHP-FPM's settings, with defaults that follow the number of children.
        export PHP_FPM_PM_MAX_CHILDREN="${PHP_FPM_PM_MAX_CHILDREN:-10}"
        export PHP_FPM_PM_START_SERVERS="${PHP_FPM_PM_START_SERVERS:-$(( PHP_FPM_PM_MAX_CHILDREN / 4 > 2 ? PHP_FPM_PM_MAX_CHILDREN / 4 : 2 ))}"
        export PHP_FPM_PM_MIN_SPARE_SERVERS="${PHP_FPM_PM_MIN_SPARE_SERVERS:-1}"
        export PHP_FPM_PM_MAX_SPARE_SERVERS="${PHP_FPM_PM_MAX_SPARE_SERVERS:-$(( PHP_FPM_PM_MAX_CHILDREN / 2 > 3 ? PHP_FPM_PM_MAX_CHILDREN / 2 : 3 ))}"
        export PHP_FPM_PM_MAX_REQUESTS="${PHP_FPM_PM_MAX_REQUESTS:-500}"
        export PHP_FPM_PROCESS_CONTROL_TIMEOUT="${PHP_FPM_PROCESS_CONTROL_TIMEOUT:-8s}"
        export PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-256M}"
        export PHP_UPLOAD_MAX_FILESIZE="${PHP_UPLOAD_MAX_FILESIZE:-100M}"
        export PHP_POST_MAX_SIZE="${PHP_POST_MAX_SIZE:-100M}"

        rm -f /tmp/php-fpm.sock
        php-fpm -F -y /usr/local/etc/php-fpm.conf &
        fpm=$!
        nginx -e /tmp/nginx-start.log -c /etc/nginx/commypanel.conf -g 'daemon off;' &
        web=$!

        # A stop signal ends both gracefully: nginx and PHP-FPM finish the requests they have, then exit.
        # (The php-fpm image's own stop signal is QUIT; the Dockerfile sets TERM, and both are handled.)
        stopping=""
        trap 'stopping=1; kill -QUIT "$web" "$fpm" 2>/dev/null || true' TERM INT QUIT

        code=0
        while [ -z "$stopping" ]; do
        	wait -n "$fpm" "$web" || code=$?
        	if [ -n "$stopping" ]; then
        		break
        	fi
        	# One of them ended on its own: stop the other and report a failure.
        	kill -QUIT "$web" "$fpm" 2>/dev/null || true
        	[ "$code" -ne 0 ] || code=1
        	died=1
        	break
        done

        # Both are reaped here (a process already reaped by `wait -n` answers 127, which is not a failure).
        f=0
        w=0
        wait "$fpm" 2>/dev/null || f=$?
        wait "$web" 2>/dev/null || w=$?
        [ "$f" -ne 127 ] || f=0
        [ "$w" -ne 127 ] || w=0

        if [ -n "${died:-}" ]; then
        	exit "$code"
        fi
        exit $(( f != 0 ? f : w ))

        SH;
    }
}
