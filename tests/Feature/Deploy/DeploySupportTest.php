<?php

use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Health\HttpHealthChecker;
use App\Deploy\PortAllocator;
use App\Deploy\Source\GitRunner;
use App\Deploy\Source\GitSourceFetcher;
use App\Deploy\Source\GitUrl;
use App\Enums\DeploymentStatus;
use App\Enums\SourceType;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

describe('git urls', function () {
    it('accepts network transports', function (string $url) {
        expect(GitUrl::isValid($url))->toBeTrue();
    })->with([
        'https://github.com/acme/shop.git',
        'https://git.example.com:8443/team/shop',
        'git@github.com:acme/shop.git',
        'ssh://git@example.com:2222/acme/shop.git',
    ]);

    it('rejects dangerous or local urls', function (string $url) {
        expect(GitUrl::isValid($url))->toBeFalse();
    })->with([
        'empty' => '',
        'option injection' => '--upload-pack=touch /tmp/x',
        'ext helper' => 'ext::sh -c touch% /tmp/x',
        'file scheme' => 'file:///etc',
        'local path' => '/var/lib/repo',
        'plain http' => 'http://example.com/a.git',
        'whitespace' => 'https://example.com/a b.git',
        'newline' => "https://example.com/a.git\nfoo",
        'leading dash' => '-oProxyCommand=x',
        'ftp' => 'ftp://example.com/a.git',
    ]);

    it('only allows local paths when explicitly enabled', function () {
        expect(GitUrl::isValid('/tmp/repo', allowLocal: true))->toBeTrue()
            ->and(GitUrl::isValid('/tmp/../etc', allowLocal: true))->toBeFalse();
    });

    it('validates branch names', function () {
        expect(GitUrl::isValidBranch('feature/new-thing_1.0'))->toBeTrue()
            ->and(GitUrl::isValidBranch('-x'))->toBeFalse()
            ->and(GitUrl::isValidBranch('a..b'))->toBeFalse()
            ->and(GitUrl::isValidBranch('a b'))->toBeFalse()
            ->and(GitUrl::isValidBranch('a;b'))->toBeFalse();
    });
});

describe('git fetcher', function () {
    beforeEach(function () {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('git is not installed.');
        }

        $this->dir = storage_path('framework/testing/git-'.bin2hex(random_bytes(4)));
        $this->repo = $this->dir.'/repo';
        File::ensureDirectoryExists($this->repo);
        File::put($this->repo.'/main.go', "package main\n");

        foreach ([
            ['git', 'init', '-q', '-b', 'main'],
            ['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'add', '.'],
            ['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-q', '-m', 'init'],
        ] as $command) {
            Process::path($this->repo)->run($command)->throw();
        }
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('clones the branch, returns the commit and drops the history', function () {
        config(['commypanel.deploy.git_allow_local' => true]);
        $app = App::factory()->make(['source_type' => SourceType::Git, 'git_url' => $this->repo, 'git_branch' => 'main']);
        $expected = trim(Process::path($this->repo)->run(['git', 'rev-parse', 'HEAD'])->output());

        $fetcher = new GitSourceFetcher(new GitRunner);
        $sha = $fetcher->fetch($app, $this->dir.'/out');

        expect($sha)->toBe($expected)
            ->and($fetcher->lastCommitMessage())->toBe('init')
            ->and($this->dir.'/out/main.go')->toBeFile()
            ->and($this->dir.'/out/.git')->not->toBeDirectory();
    });

    it('reports a missing repository or branch in plain words', function () {
        config(['commypanel.deploy.git_allow_local' => true]);
        $app = App::factory()->make(['source_type' => SourceType::Git, 'git_url' => $this->repo, 'git_branch' => 'nope']);

        expect(fn () => (new GitSourceFetcher(new GitRunner))->fetch($app, $this->dir.'/out'))
            ->toThrow(DeploymentFailed::class, 'Could not fetch the repository');
    });

    it('clones over HTTPS with the access token when the app has one', function () {
        Process::fake(['*' => Process::result('')]);
        $app = App::factory()->make([
            'source_type' => SourceType::Git, 'git_url' => 'git@github.com:acme/shop.git', 'git_branch' => 'main',
            'git_token' => 'secret-token-123', 'git_token_username' => 'x-access-token', 'deploy_key_private' => 'KEY',
        ]);

        (new GitSourceFetcher(new GitRunner))->fetch($app, $this->dir.'/out');

        Process::assertRan(fn ($process) => in_array('clone', $process->command, true)
            && in_array('https://github.com/acme/shop.git', $process->command, true)
            && ! str_contains(implode(' ', $process->command), 'secret-token-123')
            && str_starts_with($process->environment['GIT_CONFIG_VALUE_0'] ?? '', 'Authorization: Basic '));
    });

    it('refuses local paths unless enabled', function () {
        $app = App::factory()->make(['source_type' => SourceType::Git, 'git_url' => $this->repo]);

        expect(fn () => (new GitSourceFetcher(new GitRunner))->fetch($app, $this->dir.'/out'))
            ->toThrow(DeploymentFailed::class, 'not valid');
    });

    it('refuses uploads until they are supported', function () {
        $app = App::factory()->make(['source_type' => SourceType::Upload]);

        expect(fn () => (new GitSourceFetcher(new GitRunner))->fetch($app, $this->dir.'/out'))
            ->toThrow(DeploymentFailed::class, 'Only Git');
    });
});

describe('port allocator', function () {
    beforeEach(function () {
        $this->start = random_int(30000, 60000);
        config(['commypanel.deploy.port_range_start' => $this->start, 'commypanel.deploy.port_range_end' => $this->start + 5]);
    });

    it('hands out the lowest free port and skips ports of live deployments', function () {
        $app = App::factory()->create();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => $this->start]);
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'status' => DeploymentStatus::Failed, 'host_port' => $this->start + 1]);
        $new = Deployment::factory()->create(['app_id' => $app->id, 'number' => 3]);

        $port = app(PortAllocator::class)->allocate($new);

        expect($port)->toBe($this->start + 1)
            ->and($new->fresh()->host_port)->toBe($port);
    });

    it('skips ports that something else is listening on', function () {
        $busy = stream_socket_server('tcp://127.0.0.1:'.$this->start);
        $deployment = Deployment::factory()->create();

        try {
            expect(app(PortAllocator::class)->allocate($deployment))->toBe($this->start + 1);
        } finally {
            fclose($busy);
        }
    });

    it('fails when the range is exhausted', function () {
        config(['commypanel.deploy.port_range_end' => $this->start]);
        $app = App::factory()->create();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => $this->start]);
        $deployment = Deployment::factory()->create(['app_id' => $app->id, 'number' => 2]);

        expect(fn () => app(PortAllocator::class)->allocate($deployment))->toThrow(DeploymentFailed::class, 'No free port');
    });
});

describe('http health checker', function () {
    beforeEach(fn () => Sleep::fake());

    it('is healthy on any response below 500', function (int $status) {
        Http::fake(['127.0.0.1:20001/health' => Http::response('', $status)]);

        expect((new HttpHealthChecker)->waitUntilHealthy(20001, '/health', 5))->toBeTrue();
    })->with([200, 204, 301, 401, 404]);

    it('keeps trying through errors until the app answers', function () {
        Http::fake(['127.0.0.1:20001/*' => Http::sequence()->push('', 502)->push('', 500)->push('ok', 200)]);

        expect((new HttpHealthChecker)->waitUntilHealthy(20001, 'ping', 10))->toBeTrue();
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    });

    it('gives up after the timeout', function () {
        Http::fake(['127.0.0.1:20001/*' => Http::response('', 500)]);

        expect((new HttpHealthChecker)->waitUntilHealthy(20001, '/', 3))->toBeFalse();
        Http::assertSentCount(3);
    });
});

describe('queueing', function () {
    it('queues a numbered deployment and records who asked', function () {
        Queue::fake();
        $app = App::factory()->create();
        $user = User::factory()->create();

        $first = app(Deployer::class)->deploy($app, $user);
        $second = app(Deployer::class)->deploy($app, $user);

        expect([$first->number, $second->number])->toBe([1, 2])
            ->and($first->status)->toBe(DeploymentStatus::Queued);
        Queue::assertPushed(RunDeployment::class, 2);
        expect(AuditLog::query()->where('action', 'deploy.queued')->first()->user_id)->toBe($user->id);
    });

    it('marks an interrupted deployment as failed but leaves finished ones alone', function () {
        $running = Deployment::factory()->create(['status' => DeploymentStatus::Building]);
        $done = Deployment::factory()->create(['status' => DeploymentStatus::Active]);

        (new RunDeployment($running->id))->failed(new RuntimeException('killed'));
        (new RunDeployment($done->id))->failed(new RuntimeException('killed'));

        expect($running->fresh()->status)->toBe(DeploymentStatus::Failed)
            ->and($running->fresh()->error)->toBe('The deployment was interrupted.')
            ->and($done->fresh()->status)->toBe(DeploymentStatus::Active);
    });
});
