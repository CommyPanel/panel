<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The installer needs root and a real Debian/Ubuntu to do its work, so these
 * tests run it in --render-only mode: it writes every config file below a
 * scratch root and touches nothing else. They check the files it would put
 * on a server, and that the scripts are valid shell.
 */
beforeEach(function () {
    if (trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
        $this->markTestSkipped('bash is not available.');
    }

    $this->root = storage_path('framework/testing/installer-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->root);

    $this->setup = function (array $args = [], array $env = []) {
        return Process::env([
            'COMMYPANEL_ROOT' => $this->root,
            'COMMYPANEL_ADDRESS' => '203.0.113.7',
            ...$env,
        ])->run(['bash', base_path('installer/setup.sh'), '--render-only', ...$args]);
    };

    $this->read = fn (string $path) => file_get_contents($this->root.$path);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('ships valid shell scripts', function (string $script) {
    $result = Process::run(['bash', '-n', base_path($script)]);

    expect($result->successful())->toBeTrue($result->errorOutput());
})->with([
    'installer/install.sh',
    'installer/setup.sh',
    'installer/templates/commypanel-cli',
    'scripts/build-release.sh',
]);

it('renders every config file with no placeholder left over', function () {
    $result = ($this->setup)();

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

    $files = [
        '/etc/commypanel/env',
        '/etc/commypanel/install.conf',
        '/etc/nginx/conf.d/00-commypanel.conf',
        '/etc/nginx/conf.d/10-commypanel-panel.conf',
        '/etc/php/8.4/fpm/pool.d/commypanel.conf',
        '/etc/systemd/system/commypanel-agent.service',
        '/etc/systemd/system/commypanel-queue@.service',
        '/etc/systemd/system/commypanel-scheduler.service',
        '/usr/local/bin/commypanel',
    ];

    foreach ($files as $file) {
        expect($this->root.$file)->toBeFile();
        expect(($this->read)($file))->not->toContain('@@');
    }
});

it('gives the agent only the panel user as caller and runs the panel as that user', function () {
    ($this->setup)([], ['COMMYPANEL_PANEL_UID' => '1234']);

    $agent = ($this->read)('/etc/systemd/system/commypanel-agent.service');
    expect($agent)->toContain('--allow-uid 1234')
        ->toContain('--socket /run/commypanel/agent.sock')
        ->toContain('--socket-group commypanel')
        ->toContain('--data-dir /var/lib/commypanel')
        ->toContain('--nginx-sites-dir /etc/nginx/commypanel')
        ->toContain('NoNewPrivileges=yes')
        ->not->toContain('User=');

    foreach (['commypanel-queue@.service', 'commypanel-scheduler.service'] as $unit) {
        $text = ($this->read)("/etc/systemd/system/{$unit}");
        expect($text)->toContain('User=commypanel')
            ->toContain('Group=commypanel')
            ->toContain('ProtectSystem=full')
            ->toContain('ReadWritePaths=/var/lib/commypanel')
            ->toContain('Environment=LARAVEL_STORAGE_PATH=/var/lib/commypanel/storage');
    }

    expect(($this->read)('/etc/systemd/system/commypanel-queue@.service'))
        ->toContain('/usr/bin/php artisan queue:work');
    expect(($this->read)('/etc/systemd/system/commypanel-scheduler.service'))
        ->toContain('/usr/bin/php artisan schedule:work');
});

it('configures production settings and the agent driver', function () {
    ($this->setup)();

    $env = ($this->read)('/etc/commypanel/env');

    expect($env)->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_URL=https://203.0.113.7:8443')
        ->toContain('HOST_DRIVER=agent')
        ->toContain('AGENT_SOCKET=/run/commypanel/agent.sock')
        ->toContain('COMMYPANEL_DATA_PATH=/var/lib/commypanel')
        ->toContain('LARAVEL_STORAGE_PATH=/var/lib/commypanel/storage')
        ->toContain('SESSION_SECURE_COOKIE=true')
        ->toContain('ACME_STAGING=false');
    expect($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m');
    expect(decoct(fileperms($this->root.'/etc/commypanel/env') & 0777))->toBe('640');
});

it('serves the panel over TLS and only ever runs index.php', function () {
    ($this->setup)();

    $vhost = ($this->read)('/etc/nginx/conf.d/10-commypanel-panel.conf');

    expect($vhost)->toContain('listen 8443 ssl;')
        ->toContain('listen 127.0.0.1:8081;')
        ->toContain('root /opt/commypanel/current/public;')
        ->toContain('ssl_certificate /etc/commypanel/tls/panel.crt;')
        ->toContain('fastcgi_pass unix:/run/php/commypanel.sock;')
        ->toContain('fastcgi_buffer_size 16k;')
        ->toContain('$realpath_root')
        ->toContain('location ~ ^/index\.php(/|$)')
        ->toContain('return 404;')
        ->toContain('deny all;')
        ->not->toContain('http2');

    expect(($this->read)('/etc/nginx/conf.d/00-commypanel.conf'))->toContain('include /etc/nginx/commypanel/*.conf;');
});

it('gives the panel its own PHP-FPM pool owned by the panel user', function () {
    ($this->setup)([], ['COMMYPANEL_PHP_VERSION' => '8.5']);

    $pool = ($this->read)('/etc/php/8.5/fpm/pool.d/commypanel.conf');

    expect($pool)->toContain('[commypanel]')
        ->toContain('user = commypanel')
        ->toContain('listen = /run/php/commypanel.sock')
        ->toContain('listen.owner = www-data')
        ->toContain('listen.mode = 0660')
        ->toContain('env[LARAVEL_STORAGE_PATH] = /var/lib/commypanel/storage')
        ->toContain('expose_php');
});

it('honours a custom panel port everywhere', function () {
    ($this->setup)(['--panel-port', '9443']);

    expect(($this->read)('/etc/nginx/conf.d/10-commypanel-panel.conf'))->toContain('listen 9443 ssl;')
        ->and(($this->read)('/etc/commypanel/env'))->toContain('APP_URL=https://203.0.113.7:9443')
        ->and(($this->read)('/etc/commypanel/install.conf'))->toContain('PANEL_PORT=9443');
});

it('is safe to run again: the key, port and address survive an upgrade', function () {
    ($this->setup)(['--panel-port', '9443']);
    $before = ($this->read)('/etc/commypanel/env');

    $second = ($this->setup)([], ['COMMYPANEL_ADDRESS' => '198.51.100.99']);

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and($second->output())->toContain('Upgrading')
        ->and(($this->read)('/etc/commypanel/env'))->toBe($before)
        ->and(($this->read)('/etc/commypanel/install.conf'))->toContain('PANEL_PORT=9443')->toContain('SERVER_ADDR=203.0.113.7')
        ->and(($this->read)('/etc/nginx/conf.d/10-commypanel-panel.conf'))->toContain('listen 9443 ssl;');
});

it('adds settings that a newer release needs without touching existing values', function () {
    ($this->setup)();
    $path = $this->root.'/etc/commypanel/env';
    file_put_contents($path, preg_replace('/^HOST_DRIVER=.*\n/m', '', (string) file_get_contents($path)).'CUSTOM_SETTING=keep-me'."\n");

    ($this->setup)();

    $env = ($this->read)('/etc/commypanel/env');
    expect($env)->toContain('HOST_DRIVER=agent')->toContain('CUSTOM_SETTING=keep-me');
});

it('refuses to render outside a scratch root and rejects bad ports and options', function () {
    $noRoot = Process::run(['bash', base_path('installer/setup.sh'), '--render-only']);
    expect($noRoot->failed())->toBeTrue()->and($noRoot->errorOutput())->toContain('COMMYPANEL_ROOT');

    foreach (['80', '8080', '70000', 'abc', '1023'] as $port) {
        $result = ($this->setup)(['--panel-port', $port]);
        expect($result->failed())->toBeTrue("port {$port} should be rejected");
    }

    $unknown = ($this->setup)(['--bogus']);
    expect($unknown->failed())->toBeTrue();
});

it('installs a command line tool that offers the documented commands', function () {
    ($this->setup)();

    $cli = ($this->read)('/usr/local/bin/commypanel');

    foreach (['setup-url', 'admin:reset', 'status', 'logs', 'upgrade', 'version', 'artisan'] as $command) {
        expect($cli)->toContain("{$command})");
    }
    expect($cli)->toContain('Run this as root')
        ->and(decoct(fileperms($this->root.'/usr/local/bin/commypanel') & 0777))->toBe('755');
});

it('makes the bootstrap installer verify checksums before unpacking', function () {
    $installer = file_get_contents(base_path('installer/install.sh'));

    expect($installer)->toContain('sha256sum --check --status')
        ->toContain("--proto '=https'")
        ->toContain('--no-same-owner')
        ->and(strpos($installer, 'sha256sum --check'))->toBeLessThan(strpos($installer, 'tar -xzf'));
});

it('never lets the panel user write to the release code', function () {
    $setup = file_get_contents(base_path('installer/setup.sh'));

    expect($setup)->toContain('chown -R root:root "$RELEASE_DIR"')
        ->toContain('go+rX,go-w "$RELEASE_DIR"')
        ->toContain('bootstrap-cache/$VERSION');
});

describe('operating system detection', function () {
    $detect = function (string $fixture, array $env = []) {
        return Process::env($env)->run([
            'bash', base_path('tests/Fixtures/installer/detect-os.sh'),
            base_path(), base_path("tests/Fixtures/installer/os-release/{$fixture}"),
        ]);
    };

    it('reads supported systems and keeps the release version intact', function (string $fixture, string $expected) use ($detect) {
        $result = $detect($fixture);

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(trim($result->output()))->toBe($expected);
    })->with([
        // Ubuntu 26.04's os-release defines VERSION="26.04.1 LTS (...)", which once overwrote the release version.
        'ubuntu 26.04' => ['ubuntu-26.04', 'id=ubuntu version_id=26.04 codename=resolute release=0.1.0'],
        'ubuntu 24.04' => ['ubuntu-24.04', 'id=ubuntu version_id=24.04 codename=noble release=0.1.0'],
        'debian 13' => ['debian-13', 'id=debian version_id=13 codename=trixie release=0.1.0'],
    ]);

    it('refuses unsupported systems with a helpful message', function (string $fixture, string $mention) use ($detect) {
        $result = $detect($fixture);

        expect($result->failed())->toBeTrue()
            ->and($result->errorOutput())->toContain($mention)->toContain('Ubuntu 24.04 and 26.04');
    })->with([
        'ubuntu 22.04' => ['ubuntu-22.04', 'Ubuntu 22.04'],
        'debian 12' => ['debian-12', 'Debian 12'],
        'fedora' => ['fedora-42', 'Fedora'],
    ]);

    it('lets an expert override an unsupported system', function () use ($detect) {
        $result = $detect('debian-12', ['COMMYPANEL_ALLOW_UNSUPPORTED' => '1']);

        expect($result->successful())->toBeTrue()
            ->and($result->errorOutput())->toContain('continuing because COMMYPANEL_ALLOW_UNSUPPORTED=1');
    });

    it('does not run an install when the script is only sourced', function () use ($detect) {
        expect(trim($detect('ubuntu-24.04')->output()))->not->toContain('Installing CommyPanel');
    });
});

it('makes the release readable for the panel user and the tarball root world-readable', function () {
    $setup = file_get_contents(base_path('installer/setup.sh'));
    $build = file_get_contents(base_path('scripts/build-release.sh'));

    expect($setup)->toContain('chmod -R go+rX,go-w "$RELEASE_DIR"')
        ->and($build)->toContain('chmod 755 "$STAGE"')
        ->and(strpos($build, 'chmod 755 "$STAGE"'))->toBeLessThan(strpos($build, 'tar -czf'));
});

it('resumes a half-finished install instead of treating it as an upgrade', function () {
    $setup = file_get_contents(base_path('installer/setup.sh'));

    expect($setup)->toContain('[ -L "$PREFIX/current" ]')
        ->and($setup)->toContain('first install that stopped half-way');
});

describe('display', function () {
    it('shows numbered steps without colour codes when not on a terminal', function () {
        $result = ($this->setup)();

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('[1/8] Checking this server')
            ->toContain('[8/8] Waiting for the panel to answer')
            ->not->toContain("\033[");
    });

    it('accepts --verbose', function () {
        expect(($this->setup)(['--verbose'])->successful())->toBeTrue();
    });

    it('draws a progress bar that fills with the completed steps', function () {
        $script = 'export COMMYPANEL_SOURCE_ONLY=1 COMMYPANEL_ROOT='.escapeshellarg($this->root).'; source '.escapeshellarg(base_path('installer/setup.sh')).'; B_ON="#"; B_OFF="-"; progress_bar 0 8; echo; progress_bar 4 8; echo; progress_bar 8 8';
        $lines = explode("\n", trim(Process::run(['bash', '-c', $script])->output()));

        expect($lines)->toBe([str_repeat('-', 20), str_repeat('#', 10).str_repeat('-', 10), str_repeat('#', 20)]);
    });

    it('shows the end of the log and the error when a step fails, without hiding anything from the log', function () {
        $log = $this->root.'/install.log';
        $script = 'export COMMYPANEL_SOURCE_ONLY=1 COMMYPANEL_ROOT='.escapeshellarg($this->root).'; source '.escapeshellarg(base_path('installer/setup.sh')).'; '
            .'SYSTEM=1; ui_init; UI_FANCY=1; LOG_FILE='.escapeshellarg($log).'; exec >> "$LOG_FILE" 2>&1; '
            .'broken() { echo "E: unable to locate package"; false; }; step "Installing" broken';
        $result = Process::run(['bash', '-c', $script]);

        expect($result->successful())->toBeFalse()
            ->and($result->output())->toContain('Last lines of the log')->toContain('unable to locate package')
            ->and(file_get_contents($log))->toContain('unable to locate package');
    });
});
