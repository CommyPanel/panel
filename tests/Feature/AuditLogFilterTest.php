<?php

use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $make = function (string $action, ?string $email, ?string $ip, string $when = 'now') {
        $log = AuditLog::query()->create(['action' => $action, 'user_email' => $email, 'ip_address' => $ip]);
        AuditLog::query()->whereKey($log->id)->toBase()->update(['created_at' => $when === 'now' ? now() : now()->modify($when)]);

        return $log;
    };

    $make('deploy.succeeded', 'ana@example.com', '198.51.100.7');
    $make('database.credentials_viewed', 'bob@example.com', '2001:db8::1', '-3 days');
    $make('database.remote_opened', 'ana@example.com', '203.0.113.9', '-20 days');
    $make('auth.login', 'cy@example.org', '2001:db8:85a3::8a2e:370:7334', '-200 days');
    $make('database_server.deleted', null, null, '-2 years');

    $this->actions = fn (array $query = []) => collect($this->get('/settings/audit-log?'.http_build_query($query))->viewData('page')['props']['logs']['data'])->pluck('action')->sort()->values()->all();
});

it('shows everything without a filter', function () {
    expect(count(($this->actions)()))->toBe(5);
});

describe('time', function () {
    it('keeps only entries inside the range', function () {
        expect(($this->actions)(['from' => now()->subDays(7)->toIso8601String()]))->toBe(['database.credentials_viewed', 'deploy.succeeded']);
        expect(($this->actions)(['from' => now()->subDays(30)->toIso8601String(), 'to' => now()->subDays(10)->toIso8601String()]))->toBe(['database.remote_opened']);
        expect(($this->actions)(['to' => now()->subYear()->toIso8601String()]))->toBe(['database_server.deleted']);
    });

    it('ignores a range that is not a date', function () {
        $this->get('/settings/audit-log?from=nonsense')->assertSessionHasErrors('from');
    });
});

describe('risk', function () {
    it('filters by one or several grades', function () {
        expect(($this->actions)(['severity' => ['danger']]))->toBe(['database.remote_opened', 'database_server.deleted']);
        expect(($this->actions)(['severity' => ['warning']]))->toBe(['database.credentials_viewed']);
        expect(($this->actions)(['severity' => ['info']]))->toBe(['auth.login', 'deploy.succeeded']);
        expect(($this->actions)(['severity' => ['danger', 'warning']]))->toBe(['database.credentials_viewed', 'database.remote_opened', 'database_server.deleted']);
        expect(count(($this->actions)(['severity' => ['danger', 'warning', 'info']])))->toBe(5);
    });

    it('refuses a grade that does not exist', function () {
        $this->get('/settings/audit-log?severity[]=nope')->assertSessionHasErrors('severity.0');
    });
});

describe('search', function () {
    it('finds the person by email, in part and in any case', function () {
        expect(($this->actions)(['q' => 'ANA@']))->toBe(['database.remote_opened', 'deploy.succeeded']);
        expect(($this->actions)(['q' => 'example.org']))->toBe(['auth.login']);
    });

    it('finds the action, and a few words together', function () {
        expect(($this->actions)(['q' => 'remote']))->toBe(['database.remote_opened']);
        expect(($this->actions)(['q' => 'database opened']))->toBe(['database.remote_opened']);
        expect(($this->actions)(['q' => 'database ana']))->toBe(['database.remote_opened']);
    });

    it('finds an IPv4 address, whole or in part', function () {
        expect(($this->actions)(['q' => '198.51.100.7']))->toBe(['deploy.succeeded']);
        expect(($this->actions)(['q' => '203.0.']))->toBe(['database.remote_opened']);
    });

    it('finds an IPv6 address, whole, in part, in capitals, expanded or in brackets', function () {
        expect(($this->actions)(['q' => '2001:db8::1']))->toBe(['database.credentials_viewed']);
        expect(($this->actions)(['q' => '2001:DB8::1']))->toBe(['database.credentials_viewed']);
        expect(($this->actions)(['q' => '[2001:db8::1]']))->toBe(['database.credentials_viewed']);
        expect(($this->actions)(['q' => '2001:0db8:0000:0000:0000:0000:0000:0001']))->toBe(['database.credentials_viewed']);
        expect(($this->actions)(['q' => '2001:db8:85a3']))->toBe(['auth.login']);
        expect(($this->actions)(['q' => '2001:db8']))->toBe(['auth.login', 'database.credentials_viewed']);
    });

    it('treats percent and underscore as plain characters', function () {
        expect(($this->actions)(['q' => '%']))->toBe([]);
        expect(($this->actions)(['q' => 'remote_opened']))->toBe(['database.remote_opened']);
        expect(($this->actions)(['q' => 'remote.opened']))->toBe([]);
    });

    it('combines the filters', function () {
        expect(($this->actions)(['q' => 'ana', 'severity' => ['danger'], 'from' => now()->subDays(30)->toIso8601String()]))->toBe(['database.remote_opened']);
        expect(($this->actions)(['q' => 'ana', 'severity' => ['warning']]))->toBe([]);
    });
});

it('gives the page what it needs to show the filters and keeps them in the page links', function () {
    $this->get('/settings/audit-log?q=ana&severity[]=danger&range=7d')->assertInertia(fn (Assert $page) => $page
        ->where('filters.q', 'ana')
        ->where('filters.severity', ['danger'])
        ->where('filters.range', '7d'));
});
