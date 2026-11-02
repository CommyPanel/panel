<?php

use Illuminate\Support\Facades\Artisan;
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
        ->toContain('--terminal-socket /run/commypanel-terminal/terminal.sock')
        ->toContain('--terminal-socket-group www-data')
        ->toContain('RuntimeDirectory=commypanel commypanel-terminal')
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
        ->toContain('fastcgi_param HTTP_HOST $http_host;')
        ->toContain('fastcgi_buffer_size 16k;')
        ->toContain('fastcgi_buffers 8 16k;')
        ->toContain('location = /terminal {')
        ->toContain('proxy_pass http://unix:/run/commypanel-terminal/terminal.sock;')
        ->toContain('proxy_set_header Upgrade $http_upgrade;')
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

it('hardens the panel pool against the PHP2Shell request paths', function (string $version, string $line) {
    ($this->setup)([], ['COMMYPANEL_PHP_VERSION' => $version]);

    $pool = ($this->read)("/etc/php/{$version}/fpm/pool.d/commypanel.conf");

    expect($pool)->toContain("\nphp_admin_value[session.upload_progress.enabled] = 0\n")
        ->toContain("\n{$line}php_admin_value[fatal_error_backtraces] = 0\n")
        ->not->toContain('@@');
})->with([
    'PHP 8.5 has the setting, so it is switched off' => ['8.5', ''],
    'PHP 8.4 has no such setting, so the line is a comment' => ['8.4', ';'],
]);

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

    foreach (['setup-url', 'admin:reset', 'status', 'logs', 'upgrade', 'backup', 'restore', 'version', 'artisan'] as $command) {
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

describe('the question before an upgrade', function () {
    // Runs the bootstrap installer's own confirm_upgrade with the terminal replaced by a file of answers.
    $ask = function (?string $answer, int $yes = 0, string $from = '0.2.0', string $to = '0.2.2') {
        $tty = storage_path('framework/testing/tty-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists(dirname($tty));

        if ($answer !== null) {
            file_put_contents($tty, $answer);
        }

        $result = Process::env(['COMMYPANEL_TTY' => $tty])->run([
            'bash', '-c',
            'set -e; log() { echo "LOG: $*"; }; ASSUME_YES="$2";'
            .' eval "$(awk \'/^confirm_upgrade\\(\\) \\{/{p=1} p{print} p&&/^\\}/{exit}\' "$1")"; confirm_upgrade "$3" "$4"',
            'ask', base_path('installer/install.sh'), (string) $yes, $from, $to,
        ]);
        File::delete($tty);

        return $result;
    };

    it('says what happens and goes on when answered yes, or with just Enter', function (string $answer) use ($ask) {
        $result = $ask($answer);

        expect($result->successful())->toBeTrue()
            ->and($result->output())->toContain('upgraded from 0.2.0 to 0.2.2')
            ->toContain('The panel is unavailable for a short while')
            ->toContain('Your apps stay online')
            ->toContain('goes back to the')
            ->toContain('[Y/n]');
    })->with(['Enter' => "\n", 'y' => "y\n", 'Y' => "Y\n", 'yes' => "yes\n"]);

    it('stops when answered anything else', function (string $answer) use ($ask) {
        expect($ask($answer)->failed())->toBeTrue();
    })->with(['n' => "n\n", 'N' => "N\n", 'no' => "no\n", 'nonsense' => "maybe\n", 'end of input' => '']);

    it('does not ask with --yes, and goes ahead without a terminal', function () use ($ask) {
        $yes = $ask("n\n", 1);
        expect($yes->successful())->toBeTrue()->and($yes->output())->not->toContain('[Y/n]');

        $none = $ask(null);
        expect($none->successful())->toBeTrue()
            ->and($none->output())->toContain('No terminal to ask on')->not->toContain('[Y/n]');
    });

    it('words a reinstall of the same version differently', function () use ($ask) {
        expect($ask("\n", 0, '0.2.2', '0.2.2')->output())->toContain('already installed');
    });

    it('asks only after the download is verified and before anything is replaced', function () {
        $installer = (string) file_get_contents(base_path('installer/install.sh'));

        expect(strpos($installer, 'if ! confirm_upgrade'))
            ->toBeGreaterThan(strpos($installer, 'sha256sum --check'))
            ->toBeLessThan(strpos($installer, 'mv "$incoming"'))
            ->and($installer)->toContain('-y|--yes) ASSUME_YES=1');
    });
});

it('writes the panel\'s own nginx site again after an upgrade, once the panel answers', function () {
    $setup = (string) file_get_contents(base_path('installer/setup.sh'));

    expect($setup)->toContain('commypanel:sync-panel-site')
        ->and(strpos($setup, "step \"Waiting for the panel to answer\" confirm_healthy\n    refresh_panel_site"))->not->toBeFalse()
        ->and($setup)->toMatch('/refresh_panel_site\(\) \{\s+if \[ "\$SYSTEM" != 1 \] \|\| \[ "\$UPGRADE" != 1 \]; then return 0; fi/');
});

it('restores the database with the panel\'s services stopped, and starts them again even if the restore fails', function () {
    ($this->setup)();
    $cli = ($this->read)('/usr/local/bin/commypanel');

    expect($cli)->toContain('systemctl stop commypanel-scheduler commypanel-queue@1 commypanel-queue@2')
        ->toContain('commypanel:restore "$file" --force')
        ->toContain('systemctl start commypanel-queue@1 commypanel-queue@2 commypanel-scheduler')
        ->and(strpos($cli, 'systemctl start commypanel-queue@1'))->toBeGreaterThan(strpos($cli, 'commypanel:restore'))
        // A failed restore must not skip the restart: the status is kept and checked after it.
        ->and($cli)->toContain('|| status=$?')
        ->and(strpos($cli, '|| die "The restore failed'))->toBeGreaterThan(strpos($cli, 'systemctl start commypanel-queue@1'));
});

it('backs the panel database up every night', function () {
    $schedule = Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('commypanel:backup');
});
