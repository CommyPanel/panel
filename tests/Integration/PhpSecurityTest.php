<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The installer must not leave a server on a PHP with a known critical flaw
 * (PHP2Shell, CVE-2026-109618 and CVE-2026-109619). Ubuntu and Debian fix such
 * flaws without changing the upstream version, so `php -v` cannot tell: the
 * check reads the version and the package changelog. These tests run the real
 * shell functions, also with the macOS bash 3.2 where one is available.
 */
beforeEach(function () {
    $this->dir = storage_path('framework/testing/phpsec-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir.'/bin');

    // Stand-ins for dpkg-query and dpkg, so no test depends on (or reads) the real machine's packages.
    // dpkg-query answers from the file named by FAKE_PACKAGES ("name version" per line); dpkg compares with sort -V.
    File::put($this->dir.'/bin/dpkg-query', "#!/bin/sh\nawk -v p=\"\$3\" '\$1 == p { print \$2; found = 1 } END { exit !found }' \"\${FAKE_PACKAGES:-/dev/null}\"\n");
    File::put($this->dir.'/bin/dpkg', "#!/bin/sh\n[ \"\$1\" = --compare-versions ] && [ \"\$3\" = ge ] && [ \"\$(printf '%s\\n%s\\n' \"\$2\" \"\$4\" | sort -V | head -n 1)\" = \"\$4\" ]\n");
    chmod($this->dir.'/bin/dpkg-query', 0755);
    chmod($this->dir.'/bin/dpkg', 0755);

    /** The environment for a shell that sees only the stand-in package tools. */
    $this->shellEnv = function (array $packages = []): array {
        File::put($this->dir.'/packages', collect($packages)->map(fn ($version, $name) => "{$name} {$version}")->implode("\n"));

        return ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'FAKE_PACKAGES' => $this->dir.'/packages'];
    };

    /** @return list<string> the flaws the check reports for a PHP version */
    $this->gaps = function (string $version, ?string $changelog = null, ?string $conf = null, string $bash = 'bash', array $packages = []): array {
        $result = Process::env(($this->shellEnv)($packages))->run([
            $bash, '-c', '. "$1"; php_security_gaps "$2" "$3" "$4"', 'php-security',
            base_path('installer/php-security.sh'),
            $conf ?? base_path('installer/php-security.conf'),
            $version,
            $changelog ?? $this->dir.'/no-such-changelog',
        ]);

        expect($result->successful())->toBeTrue($result->errorOutput());

        return array_values(array_filter(explode("\n", trim($result->output()))));
    };

    $this->both = ['CVE-2026-109618', 'CVE-2026-109619'];
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('reports a PHP older than the fixed release of its branch', function (string $version) {
    expect(($this->gaps)($version))->toBe($this->both);
})->with([
    'the version that was found on a server' => '8.5.4',
    'one patch release before the fix on 8.5' => '8.5.11',
    '8.5.9, older than 8.5.12 (compared as numbers, not as text)' => '8.5.9',
    '8.4 before the fix' => '8.4.26',
    '8.3 before the fix' => '8.3.35',
]);

it('accepts a PHP at or beyond the fixed release', function (string $version) {
    expect(($this->gaps)($version))->toBe([]);
})->with(['8.5.12', '8.5.13', '8.5.100', '8.4.27', '8.4.30', '8.3.36', '8.3.40']);

it('does not judge a branch it knows nothing about', function (string $version) {
    expect(($this->gaps)($version))->toBe([]);
})->with(['an unsupported older branch' => '8.2.30', 'a branch that does not exist yet' => '8.9.0']);

it('ignores whatever follows the version numbers', function () {
    expect(($this->gaps)('8.5.4-dev'))->toBe($this->both)
        ->and(($this->gaps)('8.5.12RC1'))->toBe([]);
});

it('counts a backported fix named in the package changelog, as Ubuntu and Debian ship it', function () {
    File::put($this->dir.'/changelog', "php8.5 (8.5.4-0ubuntu1.5) resolute-security; urgency=medium\n\n  * SECURITY UPDATE: remote code execution via out-of-memory handling\n    - debian/patches/CVE-2026-109618.patch\n    - debian/patches/CVE-2026-109619.patch\n    - CVE-2026-109618\n    - CVE-2026-109619\n");

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe([]);
});

it('still reports a flaw the changelog does not name', function () {
    File::put($this->dir.'/changelog', "php8.5 (8.5.4-0ubuntu1.4) resolute-security; urgency=medium\n\n  * SECURITY UPDATE: something else\n    - CVE-2026-109618\n");

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe(['CVE-2026-109619']);
});

it('reads a gzipped changelog, which is how packages ship it', function () {
    File::put($this->dir.'/changelog.Debian.gz', gzencode("  * CVE-2026-109618\n  * CVE-2026-109619\n"));

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog.Debian.gz'))->toBe([]);
});

it('is not fooled by an id that only looks similar', function () {
    // A longer id, another year and lower case are all different from the two flaws.
    File::put($this->dir.'/changelog', "  * CVE-2026-1096180 and CVE-2025-109618 and cve-2026-109619 and XCVE-2026-109619\n");

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe($this->both);
});

it('finds the id wherever it stands in a line', function () {
    File::put($this->dir.'/changelog', "CVE-2026-109618\n  - (CVE-2026-109619)\n");

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe([]);

    File::put($this->dir.'/changelog', "fixes CVE-2026-109618, CVE-2026-109619.\n");

    expect(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe([]);
});

it('skips comments and blank lines, and a missing list is not an error', function () {
    File::put($this->dir.'/list.conf', "# a comment\n\n   \n  # indented comment\nCVE-2099-1 8.5=8.5.1\n");

    expect(($this->gaps)('8.5.0', null, $this->dir.'/list.conf'))->toBe(['CVE-2099-1'])
        ->and(($this->gaps)('8.5.0', null, $this->dir.'/missing.conf'))->toBe([]);
});

it('works with the bash 3.2 that macOS ships', function () {
    if (! is_executable('/bin/bash') || ! str_contains((string) shell_exec('/bin/bash --version 2>&1'), 'version 3.')) {
        $this->markTestSkipped('This machine has no bash 3.');
    }

    expect(($this->gaps)('8.5.4', null, null, '/bin/bash'))->toBe($this->both)
        ->and(($this->gaps)('8.5.12', null, null, '/bin/bash'))->toBe([]);
});

it('lists every critical flaw for every supported branch', function () {
    $lines = collect(file(base_path('installer/php-security.conf'), FILE_IGNORE_NEW_LINES))
        ->filter(fn (string $line) => str_starts_with($line, 'CVE-'));

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        foreach (['8.3=', '8.4=', '8.5='] as $branch) {
            expect($line)->toContain($branch);
        }
    }
});

it('recognises Ubuntu\'s fixed package build, whose upstream version stays the same', function (string $version, array $packages, array $expected) {
    expect(($this->gaps)($version, null, null, 'bash', $packages))->toBe($expected);
})->with([
    'Ubuntu 26.04 at the fixed build' => ['8.5.4', ['php8.5-common' => '8.5.4-0ubuntu1.5'], []],
    'Ubuntu 26.04 with a later build' => ['8.5.4', ['php8.5-common' => '8.5.4-0ubuntu1.10'], []],
    'Ubuntu 26.04 one build before the fix' => ['8.5.4', ['php8.5-common' => '8.5.4-0ubuntu1.4'], ['CVE-2026-109618', 'CVE-2026-109619']],
    'Ubuntu 26.04 without the package known' => ['8.5.4', [], ['CVE-2026-109618', 'CVE-2026-109619']],
    'Ubuntu 24.04 at the fixed build' => ['8.3.6', ['php8.3-common' => '8.3.6-0ubuntu0.24.04.13'], []],
    'Ubuntu 24.04 one build before the fix' => ['8.3.6', ['php8.3-common' => '8.3.6-0ubuntu0.24.04.12'], ['CVE-2026-109618', 'CVE-2026-109619']],
    'a fixed build of another branch says nothing about this one' => ['8.5.4', ['php8.3-common' => '8.3.6-0ubuntu0.24.04.13'], ['CVE-2026-109618', 'CVE-2026-109619']],
]);

it('lets any one of the three signs settle a flaw', function () {
    File::put($this->dir.'/changelog', "  * CVE-2026-109618\n");

    // The changelog names the first flaw; the package build covers both, so nothing is left.
    expect(($this->gaps)('8.5.4', $this->dir.'/changelog', null, 'bash', ['php8.5-common' => '8.5.4-0ubuntu1.5']))->toBe([])
        // Only the changelog: the second flaw is still open.
        ->and(($this->gaps)('8.5.4', $this->dir.'/changelog'))->toBe(['CVE-2026-109619']);
});

it('is wired into the installer: PHP is upgraded explicitly and checked before anything else is set up', function () {
    $setup = (string) file_get_contents(base_path('installer/setup.sh'));

    expect($setup)->toContain('apt-get install -y -qq --only-upgrade $php_packages')
        ->not->toContain("--only-upgrade 'php*'")
        ->and($setup)->toMatch('/packages_and_php\(\) \{\s+install_packages\s+detect_php\s+check_php_security\s+\}/')
        ->and($setup)->toContain('COMMYPANEL_ALLOW_VULNERABLE_PHP');
});

it('refuses a fresh install on a vulnerable PHP but only warns during an upgrade', function () {
    // A stand-in for the php binary that prints the version it is told to.
    File::put($this->dir.'/php', "#!/bin/sh\necho \"\${FAKE_PHP_VERSION}\"\n");
    chmod($this->dir.'/php', 0755);

    // Runs the installer's own check_php_security function with the messaging helpers replaced by echoes.
    $check = fn (string $version, string $upgrade, string $allow = '0') => Process::env(['FAKE_PHP_VERSION' => $version, ...($this->shellEnv)()])->run([
        'bash', '-c',
        'set -e; die() { echo "DIE: $*"; exit 1; }; warn() { echo "WARN: $*"; }; log() { echo "LOG: $*"; };'
        .' . "$1"; SYSTEM=1; PHP_BIN="$2"; PHP_VER=8.5; RELEASE_DIR="$3"; UPGRADE="$4"; export COMMYPANEL_ALLOW_VULNERABLE_PHP="$5";'
        .' eval "$(awk \'/^check_php_security\\(\\) \\{/{p=1} p{print} p&&/^\\}/{exit}\' "$6")"; check_php_security',
        'check', base_path('installer/php-security.sh'), $this->dir.'/php', base_path(), $upgrade, $allow, base_path('installer/setup.sh'),
    ]);

    $fresh = $check('8.5.4', '0');
    expect($fresh->failed())->toBeTrue()
        ->and($fresh->output())->toContain('DIE:')->toContain('CVE-2026-109618 CVE-2026-109619')
        ->toContain('sudo apt-get update && sudo apt-get upgrade')->toContain('COMMYPANEL_ALLOW_VULNERABLE_PHP=1');

    $upgrade = $check('8.5.4', '1');
    expect($upgrade->successful())->toBeTrue()
        ->and($upgrade->output())->toContain('WARN:')->toContain('stays exposed')->not->toContain('DIE:');

    $override = $check('8.5.4', '0', '1');
    expect($override->successful())->toBeTrue()
        ->and($override->output())->toContain('WARN:')->toContain('Continuing because COMMYPANEL_ALLOW_VULNERABLE_PHP=1');

    $fixed = $check('8.5.12', '0');
    expect($fixed->successful())->toBeTrue()
        ->and($fixed->output())->toContain('LOG: PHP 8.5.12 has the security fixes')->not->toContain('WARN:');
});

it('upgrades only the PHP packages that are installed, never a pattern that names every uninstalled one', function () {
    // A stand-in for dpkg-query: installed packages ("ii"), one removed with its config left ("rc"), and a half-installed one.
    File::put($this->dir.'/dpkg-query', "#!/bin/sh\nprintf 'ii  php8.5-cli\\nii  php8.5-fpm\\nrc  php8.4-fpm\\nii  php-common\\nhi  php8.5-broken\\nii  php-symfony-yaml:all\\n'\n");
    chmod($this->dir.'/dpkg-query', 0755);

    $result = Process::env(['PATH' => $this->dir.':'.getenv('PATH')])->run([
        'bash', '-c',
        'eval "$(awk \'/^installed_php_packages\\(\\) \\{/{p=1} p{print} p&&/^\\}/{exit}\' "$1")"; installed_php_packages',
        'check', base_path('installer/setup.sh'),
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(array_filter(explode("\n", $result->output())))->toBe(['php8.5-cli', 'php8.5-fpm', 'php-common', 'php-symfony-yaml:all']);
});
