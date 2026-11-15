<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Release signing: the bootstrap installer's own functions are run in bash with real openssl
 * (Ed25519 needs OpenSSL 1.1.1+; macOS's LibreSSL cannot, so a Homebrew one is used when present).
 */
beforeEach(function () {
    $this->openssl = collect([getenv('COMMYPANEL_OPENSSL') ?: null, 'openssl', '/opt/homebrew/bin/openssl'])
        ->filter()
        ->first(fn ($bin) => Process::run([$bin, 'genpkey', '-algorithm', 'ed25519', '-out', '/dev/null'])->successful());

    if ($this->openssl === null) {
        $this->markTestSkipped('No openssl with Ed25519 support.');
    }

    $this->dir = storage_path('framework/testing/signing-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir);

    $this->makeKey = function (string $name): void {
        Process::run([$this->openssl, 'genpkey', '-algorithm', 'ed25519', '-out', "{$this->dir}/$name.key"])->throw();
        Process::run([$this->openssl, 'pkey', '-in', "{$this->dir}/$name.key", '-pubout', '-out', "{$this->dir}/$name.pub"])->throw();
    };

    $this->sign = function (string $name, string $file = 'release.tar.gz'): void {
        Process::run([$this->openssl, 'pkeyutl', '-sign', '-inkey', "{$this->dir}/$name.key", '-rawin', '-in', "{$this->dir}/$file", '-out', "{$this->dir}/$file.sig"])->throw();
    };

    // Runs check_release_signature as install.sh defines it. $key is the installed version's key
    // file (or null), $embedded the key built into the script (or null for none).
    $this->check = function (?string $key, ?string $embedded = null, bool $unsigned = false) {
        $script = (string) file_get_contents(base_path('installer/install.sh'));
        $block = $embedded === null ? '' : trim((string) file_get_contents("{$this->dir}/$embedded.pub"))."\n";
        $script = preg_replace_callback(
            '/(# --- release key begin ---\n).*?(# --- release key end ---)/s',
            fn ($m) => $m[1].$block.$m[2],
            $script,
        );
        file_put_contents("{$this->dir}/install.sh", $script);

        return Process::env(['COMMYPANEL_OPENSSL' => $this->openssl])->run([
            'bash', '-c',
            'set -Eeuo pipefail; OPENSSL="$COMMYPANEL_OPENSSL"; ALLOW_UNSIGNED="$2"; D="$3";'
            .' eval "$(awk \'/^(die|log|warn)\\(\\) /{print} /^(embedded_key|key_fingerprint|verify_signature|check_release_signature)\\(\\) \\{/{p=1} p{print} p&&/^\\}/{p=0}\' "$1")";'
            .' check_release_signature "$D/release.tar.gz" "$D/release.tar.gz.sig" "$4" "$D"',
            'check', "{$this->dir}/install.sh", $unsigned ? '1' : '0', $this->dir, $key === null ? "{$this->dir}/none" : "{$this->dir}/$key.pub",
        ]);
    };

    file_put_contents("{$this->dir}/release.tar.gz", random_bytes(2048));
    ($this->makeKey)('release');
    ($this->makeKey)('other');
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('accepts a release signed with the installed version\'s key', function () {
    ($this->sign)('release');

    $result = ($this->check)('release');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('the key of the installed version')->toContain('signature is valid')->toContain('SHA256:');
});

it('uses the key built into the installer on a first install and on an upgrade from before signing', function () {
    ($this->sign)('release');

    $result = ($this->check)(null, 'release');

    expect($result->successful())->toBeTrue()->and($result->output())->toContain('built into this installer');
});

it('trusts the installed key, not the one in the installer, on an upgrade', function () {
    ($this->sign)('other');

    // Signed by "other", which is what the installer carries; the installed version trusts "release".
    expect(($this->check)('release', 'other')->failed())->toBeTrue();
});

it('refuses a tampered tarball', function () {
    ($this->sign)('release');
    file_put_contents("{$this->dir}/release.tar.gz", 'changed', FILE_APPEND);

    $result = ($this->check)('release');

    expect($result->failed())->toBeTrue()->and($result->errorOutput())->toContain('signature does not match');
});

it('refuses a signature made with another key', function () {
    ($this->sign)('other');

    expect(($this->check)('release')->failed())->toBeTrue();
});

it('refuses a release that has no signature', function () {
    $result = ($this->check)('release');

    expect($result->failed())->toBeTrue()->and($result->errorOutput())->toContain('not signed');
});

it('refuses when there is no key to check with', function () {
    ($this->sign)('release');

    expect(($this->check)(null, null)->errorOutput())->toContain('no release key');
});

it('goes ahead without a signature only when told to, and says so', function () {
    $result = ($this->check)('release', null, true);

    expect($result->successful())->toBeTrue()->and($result->errorOutput())->toContain('Skipping the signature check');
});

it('checks the signature before anything is unpacked, and offers --allow-unsigned', function () {
    $installer = (string) file_get_contents(base_path('installer/install.sh'));

    expect(strpos($installer, "\ncheck_release_signature \"\$WORK"))->toBeLessThan(strpos($installer, 'tar -xzf'))
        ->and($installer)->toContain('--allow-unsigned) ALLOW_UNSIGNED=1')->toContain('COMMYPANEL_ALLOW_UNSIGNED');
});

it('keeps the shipped key file and the key in the installer the same', function () {
    $installer = (string) file_get_contents(base_path('installer/install.sh'));
    preg_match('/# --- release key begin ---\n(.*?)# --- release key end ---/s', $installer, $m);
    $shipped = (string) file_get_contents(base_path('installer/release-key.pub'));

    // Both are empty until scripts/generate-signing-key.sh has run; after that they must match.
    if (str_contains($shipped, 'BEGIN PUBLIC KEY')) {
        expect(trim($m[1]))->toBe(trim($shipped));
    } else {
        expect(trim($m[1]))->toBe('');
    }
});

it('signs in the release workflow with the commands the installer verifies', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/release.yml'));

    expect($workflow)->toContain('pkeyutl -sign -inkey "$key" -rawin')
        ->toContain('RELEASE_SIGNING_KEY')
        ->toContain('commypanel.tar.gz.sig');
});

it('writes the key into the installer and the key file with the generator, and refuses to overwrite', function () {
    $repo = "{$this->dir}/repo";
    File::ensureDirectoryExists("$repo/scripts");
    File::ensureDirectoryExists("$repo/installer");
    File::copy(base_path('scripts/generate-signing-key.sh'), "$repo/scripts/generate-signing-key.sh");
    File::copy(base_path('installer/install.sh'), "$repo/installer/install.sh");
    File::copy(base_path('installer/release-key.pub'), "$repo/installer/release-key.pub");
    $env = ['COMMYPANEL_OPENSSL' => $this->openssl];

    $run = Process::env($env)->run(['bash', "$repo/scripts/generate-signing-key.sh", "{$this->dir}/new.key"]);

    expect($run->successful())->toBeTrue()
        ->and($run->output())->toContain('SHA256:')->toContain('RELEASE_SIGNING_KEY')
        ->and(file_get_contents("$repo/installer/install.sh"))->toContain('-----BEGIN PUBLIC KEY-----')
        ->and(file_get_contents("$repo/installer/release-key.pub"))->toContain('BEGIN PUBLIC KEY')
        ->and(Process::run(['bash', '-n', "$repo/installer/install.sh"])->successful())->toBeTrue()
        ->and(decoct(fileperms("{$this->dir}/new.key") & 0777))->toBe('600');

    // The generated installer must accept what the generated key signs.
    $pub = trim((string) file_get_contents("$repo/installer/release-key.pub"));
    preg_match('/# --- release key begin ---\n(.*?)# --- release key end ---/s', (string) file_get_contents("$repo/installer/install.sh"), $m);
    expect(trim($m[1]))->toBe($pub);

    // A second run must not replace the key, nor overwrite the private file.
    expect(Process::env($env)->run(['bash', "$repo/scripts/generate-signing-key.sh", "{$this->dir}/new2.key"])->failed())->toBeTrue()
        ->and(Process::env($env)->run(['bash', "$repo/scripts/generate-signing-key.sh", '--replace', "{$this->dir}/new.key"])->failed())->toBeTrue();
});
