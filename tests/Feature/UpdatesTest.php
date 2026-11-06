<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Updates\UpdateChecker;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return list<array<string, mixed>>
 */
function githubReleases(): array
{
    return [
        ['tag_name' => 'v0.3.0', 'name' => 'CommyPanel 0.3.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-12-01T10:00:00Z', 'html_url' => 'https://evil.example/phish', 'body' => "# CommyPanel 0.3.0\n\n- A new thing\n- Another thing"],
        ['tag_name' => 'v0.3.0-rc.1', 'name' => 'rc', 'draft' => false, 'prerelease' => true, 'published_at' => '2026-11-20T10:00:00Z', 'body' => 'candidate'],
        ['tag_name' => 'v0.9.9', 'name' => 'draft', 'draft' => true, 'prerelease' => false, 'body' => 'secret'],
        ['tag_name' => 'nightly', 'name' => 'odd tag', 'draft' => false, 'prerelease' => false, 'body' => 'x'],
        ['tag_name' => 'v0.2.0', 'name' => 'CommyPanel 0.2.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-11-10T10:00:00Z', 'body' => 'Second'],
        ['tag_name' => 'v0.1.2', 'name' => 'CommyPanel 0.1.2', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-31T10:00:00Z', 'body' => 'First'],
    ];
}

beforeEach(function () {
    config(['commypanel.version' => '0.1.2']);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
    $this->checker = fn (): UpdateChecker => app(UpdateChecker::class);
});

describe('asking GitHub', function () {
    it('finds the newer stable releases, newest first, and ignores pre-releases, drafts and odd tags', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        $status = ($this->checker)()->check();

        expect($status->available)->toBeTrue()
            ->and($status->latest)->toBe('0.3.0')
            ->and(array_column($status->newer, 'version'))->toBe(['0.3.0', '0.2.0'])
            ->and($status->installed['version'] ?? null)->toBe('0.1.2')
            ->and($status->error)->toBeNull()
            ->and($status->checkedAt)->not->toBeNull();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/commypanel/panel/releases?per_page=30'
            && $request->hasHeader('User-Agent', 'CommyPanel/0.1.2')
            && $request->hasHeader('Accept', 'application/vnd.github+json'));
    });

    it('builds the release address itself instead of trusting the answer', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        $newest = ($this->checker)()->check()->newer[0];

        expect($newest['url'])->toBe('https://github.com/commypanel/panel/releases/tag/v0.3.0')
            ->and($newest['name'])->toBe('CommyPanel 0.3.0')
            ->and($newest['published_at'])->toStartWith('2026-12-01');
    });

    it('compares versions the way releases are ordered', function (string $running, bool $available) {
        config(['commypanel.version' => $running]);
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        expect(($this->checker)()->check()->available)->toBe($available);
    })->with([
        'older' => ['0.1.2', true],
        'a candidate of the latest' => ['0.3.0-rc.1', true],
        'current' => ['0.3.0', false],
        'ahead of the latest stable' => ['0.4.0-rc.1', false],
    ]);

    it('does nothing for a development checkout', function () {
        config(['commypanel.version' => 'dev']);

        $status = ($this->checker)()->check();

        expect($status->supported)->toBeFalse()->and($status->available)->toBeFalse();
        Http::assertNothingSent();
    });

    it('keeps only the newest few releases', function () {
        $many = collect(range(1, 12))->map(fn (int $minor) => ['tag_name' => "v1.{$minor}.0", 'name' => "r{$minor}", 'draft' => false, 'prerelease' => false, 'body' => ''])->all();
        Http::fake(['api.github.com/*' => Http::response($many)]);

        $status = ($this->checker)()->check();

        expect($status->latest)->toBe('1.12.0')->and($status->newer)->toHaveCount(6);
    });
});

describe('remembering the answer', function () {
    it('asks again only after the answer has gone stale', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        expect(($this->checker)()->shouldCheck())->toBeTrue();
        ($this->checker)()->check();
        expect(($this->checker)()->shouldCheck())->toBeFalse();

        $this->travel(5)->hours();
        expect(($this->checker)()->shouldCheck())->toBeFalse();

        $this->travel(2)->hours();
        expect(($this->checker)()->shouldCheck())->toBeTrue();
    });

    it('keeps the last good answer when GitHub cannot be reached, and waits before trying again', function () {
        Http::fake(['api.github.com/*' => Http::sequence()->push(githubReleases())->pushFailedConnection()]);
        ($this->checker)()->check();
        $this->travel(7)->hours();

        $status = ($this->checker)()->check();

        expect($status->error)->toBe('GitHub could not be reached from this server.')
            ->and($status->available)->toBeTrue()
            ->and($status->latest)->toBe('0.3.0')
            ->and(($this->checker)()->shouldCheck())->toBeFalse();

        $this->travel(31)->minutes();
        expect(($this->checker)()->shouldCheck())->toBeTrue();
    });

    it('explains a failure without leaking details', function (callable $answer, string $message) {
        Http::fake(['api.github.com/*' => $answer]);

        expect(($this->checker)()->check()->error)->toBe($message);
    })->with([
        'rate limit' => [fn () => Http::response(['message' => 'API rate limit exceeded for 1.2.3.4'], 403), 'GitHub is limiting requests from this server right now. It will try again later.'],
        'server error' => [fn () => Http::response('oops', 502), 'GitHub answered with an error (HTTP 502).'],
        'not a list' => [fn () => Http::response('<html>nope</html>', 200), 'GitHub sent an answer that could not be read.'],
        'timeout' => [fn () => throw new ConnectionException('cURL error 28: timed out'), 'GitHub could not be reached from this server.'],
    ]);

    it('clears an old error once an answer comes through', function () {
        Http::fake(['api.github.com/*' => Http::sequence()->pushFailedConnection()->push(githubReleases())]);
        ($this->checker)()->check();

        expect(($this->checker)()->check()->error)->toBeNull();
    });
});

describe('release notes', function () {
    it('render as safe HTML', function () {
        $body = "# Title\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) and [good](https://example.com/page)\n\n![tracker](https://evil.example/pixel.png)\n\n<img src=x onerror=alert(2)>\n\n- one\n- two";
        Http::fake(['api.github.com/*' => Http::response([['tag_name' => 'v0.3.0', 'name' => 'x', 'draft' => false, 'prerelease' => false, 'body' => $body]])]);

        $html = ($this->checker)()->check()->newer[0]['notes_html'];

        expect($html)->toContain('<h1>Title</h1>')->toContain('<li>one</li>')
            ->not->toContain('<script')->not->toContain('javascript:')->not->toContain('<img')->not->toContain('onerror')
            ->toContain('href="https://example.com/page"')
            ->toContain('target="_blank"')->toContain('noopener')->toContain('noreferrer');
    });
});

describe('switching it off', function () {
    it('stops the automatic check but not a manual one', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        ($this->checker)()->setEnabled(false);

        expect(($this->checker)()->shouldCheck())->toBeFalse()
            ->and(($this->checker)()->check()->latest)->toBe('0.3.0')
            ->and(($this->checker)()->status()->summary())->toBeNull();
    });

    it('can be forbidden by the server configuration, which stops every check', function () {
        config(['commypanel.updates.check' => false]);

        $status = ($this->checker)()->check();

        expect($status->forcedOff)->toBeTrue()->and($status->enabled)->toBeFalse()
            ->and(($this->checker)()->shouldCheck())->toBeFalse();
        Http::assertNothingSent();
    });
});

describe('signing in', function () {
    it('checks in the background once, not on every sign-in', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        $user = User::factory()->create();

        event(new Login('web', $user, false));
        event(new Login('web', $user, false));

        Http::assertSentCount(1);
        expect(($this->checker)()->status()->available)->toBeTrue();
    });

    it('never lets a failure get in the way of signing in', function () {
        Exceptions::fake();
        $this->mock(UpdateChecker::class)->shouldReceive('shouldCheck')->andThrow(new RuntimeException('the cache is down'));

        event(new Login('web', User::factory()->create(), false));

        Exceptions::assertReported(RuntimeException::class);
    });

    it('checks nothing when it is switched off or this is a development checkout', function () {
        $user = User::factory()->create();

        Setting::write('updates.check', '0');
        event(new Login('web', $user, false));

        Setting::write('updates.check', '1');
        config(['commypanel.version' => 'dev']);
        event(new Login('web', $user, false));

        Http::assertNothingSent();
    });
});

describe('the page', function () {
    it('shows what is remembered without contacting GitHub', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        ($this->checker)()->check();
        Http::assertSentCount(1);

        $this->get('/settings/updates')->assertInertia(fn (Assert $page) => $page
            ->component('settings/updates')
            ->where('update.current', '0.1.2')
            ->where('update.latest', '0.3.0')
            ->where('update.available', true)
            ->where('update.supported', true)
            ->where('update.newer.0.version', '0.3.0')
            ->where('update.releases_url', 'https://github.com/commypanel/panel/releases'));

        Http::assertSentCount(1);
    });

    it('works before anything has been checked', function () {
        $this->get('/settings/updates')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('update.available', false)->where('update.latest', null)->where('update.checked_at', null));

        Http::assertNothingSent();
    });

    it('checks by hand and says what it found', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        $this->post('/settings/updates/check')->assertSessionHas('status', 'Version 0.3.0 is available.');

        expect(AuditLog::query()->where('action', 'updates.checked')->exists())->toBeTrue();
    });

    it('says when it is up to date', function () {
        config(['commypanel.version' => '0.3.0']);
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);

        $this->post('/settings/updates/check')->assertSessionHas('status', 'You are running the latest version.');
    });

    it('reports a failed check', function () {
        Http::fake(['api.github.com/*' => Http::response('', 500)]);

        $this->post('/settings/updates/check')->assertSessionHasErrors(['update' => 'GitHub answered with an error (HTTP 500).']);
    });

    it('refuses a manual check when the server configuration forbids it, or for a development checkout', function () {
        config(['commypanel.updates.check' => false]);
        $this->post('/settings/updates/check')->assertSessionHasErrors('update');

        config(['commypanel.updates.check' => true, 'commypanel.version' => 'dev']);
        $this->post('/settings/updates/check')->assertSessionHasErrors('update');

        Http::assertNothingSent();
    });

    it('switches the automatic check on and off, and records it', function () {
        $this->put('/settings/updates', ['enabled' => false])->assertSessionHasNoErrors();
        expect(($this->checker)()->enabled())->toBeFalse();

        $this->put('/settings/updates', ['enabled' => true])->assertSessionHasNoErrors();
        expect(($this->checker)()->enabled())->toBeTrue()
            ->and(AuditLog::query()->whereIn('action', ['updates.enabled', 'updates.disabled'])->count())->toBe(2);
    });

    it('does not switch anything while the server configuration forbids checks', function () {
        config(['commypanel.updates.check' => false]);

        $this->put('/settings/updates', ['enabled' => true])->assertSessionHasErrors('update');
    });
});

describe('the top bar', function () {
    it('gets a small summary on every page while an update is available', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        ($this->checker)()->check();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('update', ['current' => '0.1.2', 'latest' => '0.3.0']));
    });

    it('has nothing to show when up to date, switched off, or before the first check', function () {
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('update', null));

        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        ($this->checker)()->check();
        ($this->checker)()->setEnabled(false);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('update', null));
    });

    it('is only for people who can see the server', function () {
        Http::fake(['api.github.com/*' => Http::response(githubReleases())]);
        ($this->checker)()->check();
        $this->actingAs(User::factory()->regular()->create());

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('update', null));
    });
});
