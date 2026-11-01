<?php

use App\Deploy\Source\AccessState;
use App\Deploy\Source\GitCredentials;
use App\Deploy\Source\GitProvider;
use App\Deploy\Source\GitRunner;
use App\Deploy\Source\RepositoryAccessChecker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function accessChecker(): RepositoryAccessChecker
{
    return app(RepositoryAccessChecker::class);
}

describe('scripted git answers', function () {
    it('calls a repository public when git can list it without credentials', function () {
        Process::fake(['*ls-remote*' => Process::result("abc123\trefs/heads/main\ndef456\trefs/heads/dev\n")]);

        $result = accessChecker()->check('https://github.com/acme/shop', 'main', null);

        expect($result->state)->toBe(AccessState::Public)
            ->and($result->provider)->toBe(GitProvider::GitHub)
            ->and($result->branches)->toBe(['main', 'dev'])
            ->and($result->sshUrl)->toBe('git@github.com:acme/shop.git');
    });

    it('recognises the ways Git hosts refuse an anonymous read', function (string $stderr) {
        Process::fake(['*ls-remote*' => Process::result('', $stderr, 128)]);

        $result = accessChecker()->check('https://github.com/acme/private', 'main', null);

        expect($result->state)->toBe(AccessState::KeyRequired)
            ->and($result->sshUrl)->toBe('git@github.com:acme/private.git');
    })->with([
        'github private' => ["fatal: could not read Username for 'https://github.com': terminal prompts disabled"],
        'github wrong' => ["remote: Repository not found.\nfatal: Authentication failed for 'https://github.com/acme/private/'"],
        'gitlab' => ["remote: HTTP Basic: Access denied\nfatal: Authentication failed for 'https://gitlab.com/g/p.git/'"],
        'bitbucket' => ["fatal: unable to access 'https://bitbucket.org/t/a.git/': The requested URL returned error: 403"],
    ]);

    it('says so when the Git host cannot be reached', function (string $stderr) {
        Process::fake(['*ls-remote*' => Process::result('', $stderr, 128)]);

        expect(accessChecker()->check('https://git.example.com/a/b', 'main', null)->state)->toBe(AccessState::Unreachable);
    })->with([
        ["fatal: unable to access 'https://git.example.com/a/b/': Could not resolve host: git.example.com"],
        ['fatal: unable to access: Failed to connect: Connection refused'],
    ]);

    it('reports a missing branch and lists the real ones', function () {
        Process::fake(['*ls-remote*' => Process::result("a\trefs/heads/master\nb\trefs/heads/dev\n")]);

        $result = accessChecker()->check('https://github.com/acme/shop', 'main', null);

        expect($result->state)->toBe(AccessState::BranchMissing)
            ->and($result->message)->toContain('"main" does not exist')->toContain('master, dev');
    });

    it('explains an empty repository', function () {
        Process::fake(['*ls-remote*' => Process::result('')]);

        expect(accessChecker()->check('https://github.com/acme/new', 'main', null)->message)->toContain('no branches yet');
    });

    it('asks for a key straight away for SSH addresses, without contacting the host', function () {
        Process::fake();

        $result = accessChecker()->check('git@github.com:acme/shop.git', 'main', null);

        expect($result->state)->toBe(AccessState::KeyRequired);
        Process::assertNothingRan();
    });

    it('confirms a working deploy key and tests it over SSH', function () {
        Process::fake(['*ls-remote*' => Process::result("a\trefs/heads/main\n")]);

        $result = accessChecker()->check('https://github.com/acme/shop', 'main', GitCredentials::key('PRIVATE-KEY'));

        expect($result->state)->toBe(AccessState::KeyWorks);
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'git@github.com:acme/shop.git'));
    });

    it('says the key is not accepted yet when the host rejects it', function () {
        Process::fake(['*ls-remote*' => Process::result('', "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.", 128)]);

        $result = accessChecker()->check('git@github.com:acme/shop.git', 'main', GitCredentials::key('PRIVATE-KEY'));

        expect($result->state)->toBe(AccessState::KeyRequired)
            ->and($result->message)->toContain('not accepted yet');
    });

    it('tests an access token over HTTPS, passing it only through the environment', function () {
        Process::fake(['*ls-remote*' => Process::result("a\trefs/heads/main\n")]);

        $result = accessChecker()->check('git@github.com:acme/shop.git', 'main', GitCredentials::token('x-access-token', 'secret-token-123'));

        expect($result->state)->toBe(AccessState::TokenWorks)
            ->and($result->message)->toContain('access token works');
        Process::assertRan(function ($process) {
            $command = implode(' ', $process->command);
            $env = $process->environment;

            return str_contains($command, 'https://github.com/acme/shop.git')
                && ! str_contains($command, 'secret-token-123')
                && $env['GIT_CONFIG_KEY_0'] === 'http.extraHeader'
                && $env['GIT_CONFIG_VALUE_0'] === 'Authorization: Basic '.base64_encode('x-access-token:secret-token-123')
                && $env['GIT_CONFIG_KEY_1'] === 'http.followRedirects'
                && $env['GIT_CONFIG_VALUE_1'] === 'false'
                && ! isset($env['GIT_SSH_COMMAND']);
        });
    });

    it('says the token is not accepted when the host refuses it', function () {
        Process::fake(['*ls-remote*' => Process::result('', "fatal: Authentication failed for 'https://gitlab.com/g/p.git/'", 128)]);

        $result = accessChecker()->check('https://gitlab.com/g/p.git', 'main', GitCredentials::token('oauth2', 'glpat-abcdefgh'));

        expect($result->state)->toBe(AccessState::KeyRequired)
            ->and($result->message)->toContain('access token was not accepted');
    });

    it('refuses token credentials that could not be sent safely', function (string $username, string $token) {
        expect(fn () => GitCredentials::token($username, $token))->toThrow(InvalidArgumentException::class);
    })->with([
        'colon in username' => ['me:you', 'token-12345'],
        'newline in token' => ['me', "token\r\nX-Evil: 1"],
        'space in token' => ['me', 'token 12345'],
        'too short' => ['me', 'abc'],
    ]);

    it('rejects addresses and branches git must never see', function (string $url, string $branch) {
        Process::fake();

        expect(accessChecker()->check($url, $branch, null)->state)->toBe(AccessState::Invalid);
        Process::assertNothingRan();
    })->with([
        'option injection' => ['--upload-pack=touch /tmp/x', 'main'],
        'ext helper' => ['ext::sh -c id', 'main'],
        'file url' => ['file:///etc', 'main'],
        'local path' => ['/srv/repo', 'main'],
        'bad branch' => ['https://github.com/a/b', '-x'],
    ]);

    it('never lets git prompt, use a credential helper or an external transport', function () {
        Process::fake(['*ls-remote*' => Process::result("a\trefs/heads/main\n")]);

        accessChecker()->check('https://github.com/acme/shop', 'main', null);

        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'credential.helper=')
            && str_contains(implode(' ', $process->command), 'protocol.ext.allow=never')
            && ($process->environment['GIT_TERMINAL_PROMPT'] ?? null) === '0');
    });

    it('removes the temporary key file after the check', function () {
        config(['commypanel.data_path' => storage_path('framework/testing/keys-'.bin2hex(random_bytes(4)))]);
        Process::fake(['*ls-remote*' => Process::result("a\trefs/heads/main\n")]);

        accessChecker()->check('https://github.com/acme/shop', 'main', GitCredentials::key('PRIVATE-KEY'));

        expect(glob(config('commypanel.data_path').'/tmp/key-*'))->toBe([]);
        File::deleteDirectory(config('commypanel.data_path'));
    });
});

describe('a real repository', function () {
    beforeEach(function () {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not installed.');
        }

        config(['commypanel.deploy.git_allow_local' => true]);
        $this->dir = storage_path('framework/testing/access-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/main.go', "package main\n");

        foreach ([
            ['git', 'init', '-q', '-b', 'main'],
            ['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'add', '.'],
            ['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-q', '-m', 'init'],
        ] as $command) {
            Process::path($this->dir)->run($command)->throw();
        }
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('hands the token to the real git through its configuration', function () {
        $result = app(GitRunner::class)->run(['config', '--get-all', 'http.extraHeader'], GitCredentials::token('x-access-token', 'secret-token-123'), $this->dir);

        expect(trim($result->output()))->toBe('Authorization: Basic '.base64_encode('x-access-token:secret-token-123'));
    });

    it('finds a real branch and reports a missing one', function () {
        expect(accessChecker()->check($this->dir, 'main', null)->state)->toBe(AccessState::Public)
            ->and(accessChecker()->check($this->dir, 'nope', null)->state)->toBe(AccessState::BranchMissing);
    });
});
