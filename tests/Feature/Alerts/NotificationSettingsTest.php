<?php

use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Auth\SyncRolesAndPermissions;
use App\Dns\DnsResolver;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsResolver;

const ALERT_TARGET_HOOK = 'https://hooks.example.com/services/T000/B000/secret-token';
const ALERT_TARGET_BOT = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    app()->instance(DnsResolver::class, new FakeDnsResolver([
        'hooks.example.com' => ['a' => ['203.0.113.10']],
        'api.telegram.org' => ['a' => ['149.154.167.220']],
    ]));
    $this->user = User::factory()->create();
    $this->confirmed = fn () => $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->form = ['name' => 'Team chat', 'kind' => 'slack', 'address' => ALERT_TARGET_HOOK, 'events' => ['deployment.failed', 'app.down'], 'enabled' => true];
});

describe('the page', function () {
    it('hands over targets without their address, the events and the kinds', function () {
        ($this->confirmed)()->post('/settings/notifications', $this->form)->assertSessionHasNoErrors();
        ($this->confirmed)()->post('/settings/notifications', ['name' => 'Bot', 'kind' => 'telegram', 'address' => ALERT_TARGET_BOT, 'chat_id' => '-1001234567890'])->assertSessionHasNoErrors();

        $response = ($this->confirmed)()->get('/settings/notifications');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/notifications')
            ->has('targets', 2)
            ->where('targets.0.name', 'Team chat')->where('targets.0.host', 'hooks.example.com')->where('targets.0.kind_label', 'Slack (also Mattermost and Rocket.Chat)')
            ->where('targets.0.events', ['deployment.failed', 'app.down'])->where('targets.0.chat_id', null)
            ->where('targets.1.host', 'api.telegram.org')->where('targets.1.chat_id', '…890')
            ->where('targets.1.events', AlertEvent::defaults())
            ->has('events', count(AlertEvent::cases()), fn (Assert $e) => $e->hasAll(['key', 'label', 'group', 'default_on']))
            ->has('kinds', 6, fn (Assert $k) => $k->hasAll(['value', 'label', 'address_label', 'hint', 'needs_chat_id']))
            ->where('max_targets', 10));

        $json = json_encode($response->viewData('page'));
        expect($json)->not->toContain('secret-token')->not->toContain(ALERT_TARGET_BOT)->not->toContain('1001234567890');
    });

    it('stores the address encrypted', function () {
        ($this->confirmed)()->post('/settings/notifications', $this->form);

        expect(DB::table('alert_targets')->value('address'))->not->toContain('secret-token')
            ->and(AlertTarget::query()->first()->secret())->toBe(ALERT_TARGET_HOOK);
    });

    it('needs the permission', function () {
        $target = AlertTarget::query()->create(['name' => 'x', 'kind' => 'slack', 'address' => ALERT_TARGET_HOOK, 'events' => []]);
        $nobody = User::factory()->regular()->create();

        $this->actingAs($nobody)->get('/settings/notifications')->assertForbidden();
        $this->actingAs($nobody)->post('/settings/notifications', $this->form)->assertForbidden();
        $this->actingAs($nobody)->put("/settings/notifications/{$target->id}", $this->form)->assertForbidden();
        $this->actingAs($nobody)->put("/settings/notifications/{$target->id}/toggle")->assertForbidden();
        $this->actingAs($nobody)->post("/settings/notifications/{$target->id}/test")->assertForbidden();
        $this->actingAs($nobody)->delete("/settings/notifications/{$target->id}")->assertForbidden();
    });

    it('asks for the password to create, change or remove', function () {
        $this->actingAs($this->user)->post('/settings/notifications', $this->form)->assertRedirect();
        expect(AlertTarget::query()->count())->toBe(0);

        ($this->confirmed)()->post('/settings/notifications', $this->form);
        $target = AlertTarget::query()->first();
        $this->flushSession();

        $this->actingAs($this->user)->put("/settings/notifications/{$target->id}", [...$this->form, 'name' => 'New'])->assertRedirect();
        $this->actingAs($this->user)->delete("/settings/notifications/{$target->id}")->assertRedirect();
        expect($target->fresh()->name)->toBe('Team chat');

        ($this->confirmed)()->delete("/settings/notifications/{$target->id}");
        expect(AlertTarget::query()->count())->toBe(0);
    });
});

describe('creating and changing', function () {
    it('gives a new target the default events when none are sent', function () {
        ($this->confirmed)()->post('/settings/notifications', ['name' => 'A', 'kind' => 'discord', 'address' => ALERT_TARGET_HOOK]);

        expect(AlertTarget::query()->first()->events)->toBe(AlertEvent::defaults());
    });

    it('keeps the saved address when the field is left empty', function () {
        ($this->confirmed)()->post('/settings/notifications', $this->form);
        $target = AlertTarget::query()->first();

        ($this->confirmed)()->put("/settings/notifications/{$target->id}", [...$this->form, 'address' => '', 'name' => 'Renamed', 'events' => ['backup.failed']])->assertSessionHasNoErrors();

        expect($target->fresh()->secret())->toBe(ALERT_TARGET_HOOK)->and($target->fresh()->name)->toBe('Renamed')->and($target->fresh()->events)->toBe(['backup.failed']);
    });

    it('replaces the address when a new one is sent', function () {
        ($this->confirmed)()->post('/settings/notifications', $this->form);
        $target = AlertTarget::query()->first();

        ($this->confirmed)()->put("/settings/notifications/{$target->id}", [...$this->form, 'address' => 'https://hooks.example.com/new'])->assertSessionHasNoErrors();
        expect($target->fresh()->secret())->toBe('https://hooks.example.com/new');
    });

    it('refuses addresses that point at this server or are not https, for every url kind', function (string $kind, string $url) {
        ($this->confirmed)()->post('/settings/notifications', [...$this->form, 'kind' => $kind, 'address' => $url])->assertSessionHasErrors('address');
        expect(AlertTarget::query()->count())->toBe(0);
    })->with(function () {
        foreach (['slack', 'discord', 'teams', 'ntfy', 'json'] as $kind) {
            foreach (['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://localhost/x', 'https://169.254.169.254/x', 'https://[::1]/x', 'https://user:pw@hooks.example.com/x'] as $url) {
                yield "{$kind} {$url}" => [$kind, $url];
            }
        }
    });

    it('checks the Telegram token and chat id', function (string $token, string $chat, bool $valid) {
        $response = ($this->confirmed)()->post('/settings/notifications', ['name' => 'Bot', 'kind' => 'telegram', 'address' => $token, 'chat_id' => $chat]);

        $valid ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors();
        expect(AlertTarget::query()->count())->toBe($valid ? 1 : 0);
    })->with([
        'good group' => [ALERT_TARGET_BOT, '-1001234567890', true],
        'good user' => [ALERT_TARGET_BOT, '42', true],
        'good channel name' => [ALERT_TARGET_BOT, '@my_channel', true],
        'a url instead of a token' => ['https://api.telegram.org/bot1/x', '42', false],
        'no colon' => ['123456789AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', '42', false],
        'short secret' => ['123456789:short', '42', false],
        'bad chat id' => [ALERT_TARGET_BOT, 'not a chat', false],
        'chat id too short name' => [ALERT_TARGET_BOT, '@ab', false],
    ]);

    it('needs a chat id for Telegram', function () {
        ($this->confirmed)()->post('/settings/notifications', ['name' => 'Bot', 'kind' => 'telegram', 'address' => ALERT_TARGET_BOT])->assertSessionHasErrors('chat_id');
    });

    it('refuses an unknown kind or event, and an eleventh target', function () {
        ($this->confirmed)()->post('/settings/notifications', [...$this->form, 'kind' => 'carrier-pigeon'])->assertSessionHasErrors('kind');
        ($this->confirmed)()->post('/settings/notifications', [...$this->form, 'events' => ['nope']])->assertSessionHasErrors('events.0');

        foreach (range(1, 10) as $i) {
            AlertTarget::query()->create(['name' => "T{$i}", 'kind' => 'slack', 'address' => ALERT_TARGET_HOOK, 'events' => []]);
        }
        ($this->confirmed)()->post('/settings/notifications', $this->form)->assertSessionHasErrors('name');
        expect(AlertTarget::query()->count())->toBe(10);
    });

    it('switches a target on and off', function () {
        $target = AlertTarget::query()->create(['name' => 'x', 'kind' => 'slack', 'address' => ALERT_TARGET_HOOK, 'events' => [], 'enabled' => true]);

        $this->actingAs($this->user)->put("/settings/notifications/{$target->id}/toggle");
        expect($target->fresh()->enabled)->toBeFalse();
        $this->actingAs($this->user)->put("/settings/notifications/{$target->id}/toggle");
        expect($target->fresh()->enabled)->toBeTrue();
    });

    it('sends a test message to a saved target and shows a failure', function () {
        Http::fake(['*' => Http::sequence()->push('ok')->push('no', 404)]);
        $target = AlertTarget::query()->create(['name' => 'x', 'kind' => 'slack', 'address' => ALERT_TARGET_HOOK, 'events' => []]);

        $this->actingAs($this->user)->post("/settings/notifications/{$target->id}/test")->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ALERT_TARGET_HOOK && str_contains($r->data()['text'], 'test message'));

        $this->actingAs($this->user)->post("/settings/notifications/{$target->id}/test")->assertSessionHasErrors('alerts');
    });
});

describe('the audit log', function () {
    it('records the name and kind but never the address', function () {
        ($this->confirmed)()->post('/settings/notifications', $this->form);
        $target = AlertTarget::query()->first();
        ($this->confirmed)()->put("/settings/notifications/{$target->id}", [...$this->form, 'address' => 'https://hooks.example.com/other-secret']);
        ($this->confirmed)()->post('/settings/notifications', ['name' => 'Bot', 'kind' => 'telegram', 'address' => ALERT_TARGET_BOT, 'chat_id' => '42']);
        ($this->confirmed)()->delete("/settings/notifications/{$target->id}");

        $all = AuditLog::query()->whereIn('action', ['notifications.changed', 'notifications.removed'])->get();

        expect($all)->toHaveCount(4)
            ->and(json_encode($all->pluck('metadata')))->toContain('Team chat', 'slack', 'hooks.example.com')
            ->not->toContain('secret-token')->not->toContain('other-secret')->not->toContain(ALERT_TARGET_BOT);
        expect($all->where('action', 'notifications.removed')->first()->metadata['name'])->toBe('Team chat');
    });
});

describe('the per-app switch', function () {
    it('mutes and unmutes an app, audited, and shows it on the settings', function () {
        $app = App::factory()->create(['slug' => 'shop']);
        expect($app->fresh()->alerts_enabled)->toBeTrue();

        $this->actingAs($this->user)->put('/apps/shop/alerts', ['enabled' => false])->assertSessionHasNoErrors();
        expect($app->fresh()->alerts_enabled)->toBeFalse()
            ->and(AuditLog::query()->where('action', 'app.alerts_changed')->first()->metadata)->toBe(['app' => 'shop', 'enabled' => false]);

        $this->actingAs($this->user)->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.alerts_enabled', false));

        $this->actingAs($this->user)->put('/apps/shop/alerts', ['enabled' => true]);
        expect($app->fresh()->alerts_enabled)->toBeTrue();
    });

    it('needs apps.update and a value', function () {
        App::factory()->create(['slug' => 'shop']);
        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view');

        $this->actingAs($viewer)->put('/apps/shop/alerts', ['enabled' => false])->assertForbidden();
        $this->actingAs($this->user)->put('/apps/shop/alerts', [])->assertSessionHasErrors('enabled');
    });
});

describe('the old single webhook', function () {
    beforeEach(function () {
        DB::table('alert_targets')->delete();
        $this->migrate = fn () => (require database_path('migrations/2026_11_09_100000_create_alert_targets_and_states.php'))->migrateOldSetting();
        $this->old = fn (array $extra = []) => Setting::write('alerts.webhook', json_encode([
            'enabled' => true, 'format' => 'discord', 'events' => 'failures', 'url' => Crypt::encryptString(ALERT_TARGET_HOOK),
            'last_ok_at' => '2026-11-01T10:00:00+00:00', 'last_error' => null, ...$extra,
        ]));
    });

    it('becomes a target named Default with the same address', function () {
        ($this->old)();
        ($this->migrate)();

        $target = AlertTarget::query()->sole();

        expect($target->name)->toBe('Default')->and($target->kind)->toBe(AlertKind::Discord)
            ->and($target->secret())->toBe(ALERT_TARGET_HOOK)->and($target->enabled)->toBeTrue()
            ->and($target->events)->toBe(['deployment.failed', 'deployment.rolled_back'])
            ->and($target->last_ok_at)->not->toBeNull()
            ->and(Setting::read('alerts.webhook'))->toBeNull();
    });

    it('also listens to successes when it asked for every result', function () {
        ($this->old)(['events' => 'all', 'enabled' => false]);
        ($this->migrate)();

        expect(AlertTarget::query()->sole()->events)->toBe(['deployment.failed', 'deployment.rolled_back', 'deployment.succeeded'])
            ->and(AlertTarget::query()->sole()->enabled)->toBeFalse();
    });

    it('makes nothing when there was no address, and still removes the setting', function () {
        ($this->old)(['url' => '']);
        ($this->migrate)();

        expect(AlertTarget::query()->count())->toBe(0)->and(Setting::read('alerts.webhook'))->toBeNull();
    });
});
