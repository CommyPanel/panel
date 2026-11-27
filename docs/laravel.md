# Laravel apps

CommyPanel builds and runs Laravel apps from a Git repository or an uploaded project. A Laravel app is recognised by its `composer.json` (it requires `laravel/framework`) and its `artisan` file, or you choose Laravel as the type when you create the app. A repository with its own `Dockerfile` is built from that Dockerfile as usual.

## The steps

Creating a Laravel app adds steps to the wizard:

1. **Basics** and **Repository access** (or **Upload code**), as for any app. When access works, CommyPanel looks at your code to find the Laravel version, the PHP version, how assets are built and which services it may need. It reads files; it never runs your code.
2. **Settings**: the PHP version (8.2 to 8.5, from your `composer.json` when it names one), how assets are built (npm, pnpm, yarn, or not at all, from your lock file), the health check path (`/up`), and the usual limits.
3. **Database**: use an existing database on a database server you already run (PostgreSQL, MySQL or MariaDB), have a new database made on an existing server, make a **new database server just for this app**, or use none (SQLite, or no database). The connection arrives as Laravel's own variables (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_URL`) and follows a password change.
4. **Services**: processes that run beside the web server, each in its own container from the same release. Each service is a card with a switch; a service that is off is faded and its command is closed. The **scheduler** (`php artisan schedule:work`) is on by default; a **queue worker**, **Horizon**, **Pulse** and **Inertia SSR** are listed when your code uses them, switched off until you switch them on. **Customize** opens a service's command, grace time and memory limit, and you can add your own. Commands run without a shell, in the app's folder. The same step asks about **Redis**: none, one of your own Redis apps, or a new Redis made for this app (suggested when your code uses it, for example with Horizon or predis).
5. **Volumes**: the `storage` folder is suggested, so uploads, logs and caches survive releases. Remove it, change it or add others.
6. **Domain**, as for any app. A wildcard (`*.example.com`) works too; see _Domains and HTTPS_.
7. **Review**: a summary, the **release script**, and your variables as a `.env` text. Then CommyPanel builds and deploys.

## Redis

Choose **Make a Redis just for this app** and CommyPanel creates a ready-made Redis app named after your app, links it, and starts it. The connection arrives as `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT` and `REDIS_PASSWORD`, which Laravel's Redis configuration reads. With Redis linked, the starting `.env` text uses it for cache and sessions, and for the queue when a worker or Horizon runs. You can also use a Redis app you already run. Horizon needs Redis.

## What the image is

The image is built for you from a multi-stage Dockerfile: Composer packages, your assets (when there is a build script), and a runtime with **nginx and php-fpm** in one container (the official `php` image for your PHP version). The web process listens on the app's port; the scheduler, workers and the rest start the same image with another command.

- nginx serves your static files and passes only `index.php` to php-fpm. A `.php` file in an upload folder, and dotfiles such as `.env`, are answered with 404.
- Laravel sees **HTTPS and the real visitor address** behind CommyPanel's nginx: the container believes `X-Forwarded-Proto` and `X-Real-IP` only from private addresses, so `url()`, `asset()` and redirects are `https://` without a trusted-proxy setting in your code. Behind Cloudflare, the address is the one CommyPanel's nginx saw (Cloudflare's).
- On every start a small script creates the folders Laravel needs inside `storage` (an empty volume hides them), runs `php artisan storage:link` and, unless the variable `COMMYPANEL_OPTIMIZE` is `false`, `php artisan optimize` with your real variables. If that fails it says so in the log and starts anyway.
- Your configuration is the **environment**, not a file: a stray `.env` in your repository is left out of the image, and php-fpm passes the environment on to PHP.
- Everything is written to the container's log (nginx access and error logs, PHP errors, `LOG_CHANNEL=stderr`), so it appears on the Logs tab.
- Your assets are built with the manager you chose. The install step also copies the package manager's settings files when they exist (`pnpm-workspace.yaml`, `.npmrc`, `.pnpmfile.cjs`, `.yarnrc.yml`), so overrides and registry settings apply. When your build needs PHP, for example the Laravel Vite plugin for Wayfinder that runs `php artisan wayfinder:generate`, the assets are built in a stage that has both PHP and Node, with your Composer packages (dev packages too when a build tool needs them); the PHP part is not in the final image.
- PHP extensions come from your `composer.json` (`ext-*`) plus a common set (database drivers, redis, gd, intl, zip, bcmath, pcntl, opcache). Images are around 800 to 900 MB.
- Stopping is graceful: requests still running when the app is told to stop get up to 8 seconds to finish (`PHP_FPM_PROCESS_CONTROL_TIMEOUT`), and Docker waits 10 seconds by default.

### Settings you can change with variables

All are optional. Add them on the Environment tab.

| Variable                                       | Default | What it does                                                                                                               |
| ---------------------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------------- |
| `PHP_FPM_PM_MAX_CHILDREN`                      | 10      | Most PHP workers at once. Also `PHP_FPM_PM_START_SERVERS`, `PHP_FPM_PM_MIN_SPARE_SERVERS`, `PHP_FPM_PM_MAX_SPARE_SERVERS`. |
| `PHP_FPM_PM_MAX_REQUESTS`                      | 500     | Requests a worker serves before it is replaced.                                                                            |
| `PHP_FPM_PROCESS_CONTROL_TIMEOUT`              | 8s      | How long running requests get to finish when the app stops.                                                                |
| `PHP_MEMORY_LIMIT`                             | 256M    | PHP's memory limit per request.                                                                                            |
| `PHP_UPLOAD_MAX_FILESIZE`, `PHP_POST_MAX_SIZE` | 100M    | Upload limits inside the container.                                                                                        |
| `NGINX_CLIENT_MAX_BODY_SIZE`                   | 100m    | The container's nginx body limit.                                                                                          |
| `COMMYPANEL_OPTIMIZE`                          | on      | Set to `false` to skip `php artisan optimize` on start.                                                                    |

## Variables: the .env editor

A Laravel app's **Environment** tab shows the variables as one `.env` text, with comments and order kept. The values are secrets, so the text is loaded only after you confirm your password, and that is recorded in the audit log (names only for changes, never values). The first text is generated for you: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `LOG_CHANNEL=stderr` (so logs reach the Logs tab), and queue, cache and session drivers matching the services you chose. Saving applies the difference; press **Apply changes** (or deploy) for the running app to use it. Variables from links, such as the `DB_` ones, are not part of the text.

## The release script

Before every release goes live, CommyPanel runs your **release script**, one command per line, in a container from the new image with your variables, volumes and links. The default is `php artisan migrate --force`. Any shell syntax works. If a line fails, nothing changes and the old release keeps serving. Edit it on the Review step or on the app's **Deployment script** page under Configuration.

Rolling back brings back the old code but not the old database: write migrations that the previous release can still run against (add first, remove in a later release).

## Releases with several processes

Each release starts the web container and its enabled services, checks the web container's health, then moves traffic.

- **Workers** may run beside the old release's workers for a moment and stop gracefully (the grace time is a setting per service).
- The **scheduler**, **Horizon**, **Pulse** and **Inertia SSR** must never run twice, so the old one is stopped at go-live and the new one starts right after: expect a gap of a few seconds.
- A rollback restores every service of the old release.
- Stop, start, restart, deactivate and delete cover all of an app's containers. The Logs tab, the Terminal and the Usage tab have a selector for the container.

## A database server for one app

The dedicated option makes a database server only this app uses. Its user may **create and drop databases** on the server by itself (full rights on that server), which multi-database and tenancy packages need. The databases the app makes are not listed under Databases and are not part of the panel's backups. Deleting the app **keeps** the server and its data; it becomes an ordinary server.

## Good to know

- Uploads over 1 MB also need a higher limit in CommyPanel's own nginx: new Laravel apps get `client_max_body_size 100m;` on the Nginx tab, which you can change.
- Inertia SSR runs in its own container: set `INERTIA_SSR_URL` to that service's address (the Services page shows it).
- **Not supported yet:** Laravel Reverb (it needs a public websocket port), Laravel Octane (bring your own Dockerfile to run it) and private Composer packages (`auth.json`).
- The web server and php-fpm run together in one container: if either stops, the container stops and is started again.
