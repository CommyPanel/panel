<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel Data Directory
    |--------------------------------------------------------------------------
    |
    | Everything the panel owns on disk lives here: the SQLite databases,
    | certificates and app data. Production installs use /var/lib/commypanel.
    |
    */

    'data_path' => env('COMMYPANEL_DATA_PATH', storage_path('app/commypanel')),

    /*
    |--------------------------------------------------------------------------
    | Host Driver
    |--------------------------------------------------------------------------
    |
    | How the panel performs privileged host operations (Docker, nginx, ...).
    | "fake" only records what would happen and is used in tests and on macOS.
    | "agent" talks to commypanel-agent over its Unix socket.
    |
    */

    'host_driver' => env('HOST_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Agent Socket
    |--------------------------------------------------------------------------
    |
    | Unix socket of commypanel-agent, used by the "agent" host driver. The
    | agent only runs on Linux; `agent_allow_non_linux` exists solely so a
    | dry-run agent can be used during development on other systems.
    |
    */

    // Loopback-only port where nginx serves the panel over plain HTTP, so the
    // agent-managed site for the panel's own domain can proxy to it.
    // When the server has no public address on its own interfaces (it sits behind a NAT, as on
    // AWS or Oracle), the panel asks api.ipify.org once, in the background, which address it
    // connects from. Set this to false to never do that and enter the address by hand.
    'address_lookup' => (bool) env('COMMYPANEL_ADDRESS_LOOKUP', true),

    // The panel warns on every page once the disk holding its data is this full.
    'disk' => [
        'warn_percent' => (int) env('DISK_WARN_PERCENT', 85),
        'critical_percent' => (int) env('DISK_CRITICAL_PERCENT', 95),
    ],

    'panel_internal_port' => (int) env('PANEL_INTERNAL_PORT', 8081),

    'agent_socket' => env('AGENT_SOCKET', '/run/commypanel/agent.sock'),

    'agent_allow_non_linux' => (bool) env('AGENT_ALLOW_NON_LINUX', false),

    /*
    |--------------------------------------------------------------------------
    | Deployments
    |--------------------------------------------------------------------------
    */

    'updates' => [
        // Whether the panel may ask GitHub for new releases at all. The UI has
        // its own switch; this one is for people who want no outbound calls.
        'check' => (bool) env('COMMYPANEL_UPDATE_CHECK', true),

        // Where releases are published.
        'repository' => 'emrecanful/commypanel',

        // A successful answer is reused for this long; after a failure the
        // next attempt waits for the retry delay.
        'ttl_hours' => 6,
        'retry_minutes' => 30,
        'timeout' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    | The release tarball carries a VERSION file; a checkout does not, and
    | reports "dev" (which switches update checks off).
    */

    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

    'deploy' => [
        // Host ports (bound on 127.0.0.1) handed to app containers.
        'port_range_start' => (int) env('DEPLOY_PORT_START', 20000),
        'port_range_end' => (int) env('DEPLOY_PORT_END', 29999),

        // Seconds to wait for a new container to answer before failing the deploy.
        'health_timeout' => (int) env('DEPLOY_HEALTH_TIMEOUT', 60),

        // Seconds the old container keeps running after traffic has moved.
        'drain_seconds' => (int) env('DEPLOY_DRAIN_SECONDS', 10),

        // Release images kept per app so rollbacks stay possible.
        'keep_images' => (int) env('DEPLOY_KEEP_IMAGES', 3),

        // Seconds a release command (e.g. migrations) may run before the deploy fails.
        'release_timeout' => (int) env('DEPLOY_RELEASE_TIMEOUT', 600),

        'git_timeout' => (int) env('DEPLOY_GIT_TIMEOUT', 300),

        // Tests only: allow cloning from local paths.
        'git_allow_local' => (bool) env('DEPLOY_GIT_ALLOW_LOCAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTPS (Let's Encrypt)
    |--------------------------------------------------------------------------
    |
    | Certificates are issued by commypanel-agent using the HTTP-01 challenge.
    | Outside production the staging CA is the default: its certificates are
    | not trusted by browsers but it has generous rate limits.
    |
    */

    'https' => [
        'staging' => (bool) env('ACME_STAGING', env('APP_ENV') !== 'production'),

        // Renew when a certificate has fewer than this many days left.
        'renew_before_days' => (int) env('ACME_RENEW_BEFORE_DAYS', 30),

        // Wait this long before retrying a failed issuance.
        'retry_after_hours' => (int) env('ACME_RETRY_AFTER_HOURS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads ("I will upload my project")
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        // Largest accepted archive, in megabytes. nginx and PHP-FPM are configured to match.
        'max_archive_mb' => (int) env('UPLOAD_MAX_ARCHIVE_MB', 64),

        // Limits on what an archive may unpack to, to stop archive bombs.
        'max_unpacked_mb' => (int) env('UPLOAD_MAX_UNPACKED_MB', 500),
        'max_files' => (int) env('UPLOAD_MAX_FILES', 20000),
    ],

    'databases' => [
        // Official image tags that can be chosen, newest first.
        'versions' => [
            'mariadb' => ['11.4', '10.11'],
            'mysql' => ['8.4', '8.0'],
            'postgres' => ['17', '16', '15'],
        ],
        'memory_mb' => (int) env('DATABASE_MEMORY_MB', 1024),
        // How long a new server may take to answer its first query.
        'ready_timeout' => (int) env('DATABASE_READY_TIMEOUT', 240),
    ],

    'backups' => [
        // How many panel backups are kept; the oldest go first.
        'keep' => (int) env('BACKUPS_KEEP', 7),
        // How many dumps are kept of each database.
        'keep_databases' => (int) env('BACKUPS_KEEP_DATABASES', 7),
        // How many archives are kept of each volume. They hold whole folders, so the default is small.
        'keep_volumes' => (int) env('BACKUPS_KEEP_VOLUMES', 3),
    ],
];
