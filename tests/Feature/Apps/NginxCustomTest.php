<?php

use App\Deploy\Traffic\AppRouting;
use App\Enums\DeploymentStatus;
use App\Host\Data\NginxSite;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $live = Deployment::factory()->create([
        'app_id' => $this->shop->id, 'number' => 3, 'status' => DeploymentStatus::Active, 'host_port' => 20007,
        'container_name' => 'shop-3',
    ]);
    $this->shop->update(['active_deployment_id' => $live->id]);
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);

    // Saving needs a recent password confirmation (the editor asks for it first).
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->save = fn (?string $custom) => ($this->confirmed)()->put('/apps/shop/nginx', ['custom' => $custom]);

    /** @return list<NginxSite> every site the fake applied, oldest first */
    $this->applied = fn () => collect($this->host->actions())->where('action', 'applyNginxSite')->pluck('args.site')->all();
});

describe('reading', function () {
    it('returns the saved text and the generated config next to it', function () {
        $this->shop->update(['nginx_custom' => "client_max_body_size 50m;\n# note"]);

        $json = $this->getJson('/apps/shop/nginx')->assertOk()->json();

        expect($json['custom'])->toBe("client_max_body_size 50m;\n# note")
            ->and($json['active'])->toBeTrue()
            ->and($json['inactive_reason'])->toBeNull()
            ->and($json['generated'])->toContain('nginx site shop for shop.example.com -> 127.0.0.1:20007')->toContain('client_max_body_size 50m;')
            ->and($json['problem'])->toBeNull();
    });

    it('says why there is no generated config yet', function (array $remove, string $reason) {
        if (in_array('release', $remove, true)) {
            $this->shop->update(['active_deployment_id' => null]);
        }
        if (in_array('domain', $remove, true)) {
            Domain::query()->delete();
        }

        $this->getJson('/apps/shop/nginx')->assertOk()->assertJson(['active' => false, 'generated' => null, 'inactive_reason' => $reason, 'custom' => '']);
    })->with([
        'no release' => [['release'], 'The app has no nginx site yet: it needs a live release.'],
        'no domain' => [['domain'], 'The app has no nginx site yet: it needs a domain.'],
        'neither' => [['release', 'domain'], 'The app has no nginx site yet: it needs a live release and a domain.'],
    ]);
});

describe('saving', function () {
    it('checks the text, applies the site with it and records who changed what', function () {
        ($this->save)("# uploads\nclient_max_body_size 50m;")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Saved. nginx accepted the configuration and reloaded.');

        expect($this->shop->fresh()->nginx_custom)->toBe("# uploads\nclient_max_body_size 50m;")
            ->and($this->host->actionNames())->toBe(['checkNginxCustom', 'applyNginxSite'])
            ->and(($this->applied)()[0]->custom)->toBe("# uploads\nclient_max_body_size 50m;")
            ->and(($this->applied)()[0]->upstreamPort)->toBe(20007);

        $audit = AuditLog::query()->where('action', 'app.nginx_updated')->firstOrFail();
        expect($audit->metadata)->toMatchArray(['app' => 'shop', 'before' => null, 'after' => "# uploads\nclient_max_body_size 50m;"]);
    });

    it('normalises line endings and trailing blank space', function () {
        ($this->save)("gzip on;\r\nproxy_read_timeout 90s;\r\n\r\n  \n")->assertSessionHasNoErrors();

        expect($this->shop->fresh()->nginx_custom)->toBe("gzip on;\nproxy_read_timeout 90s;");
    });

    it('keeps the directives on every later sync, so a deploy does not lose them', function () {
        ($this->save)('client_max_body_size 50m;');

        app(AppRouting::class)->sync($this->shop->fresh());

        expect(collect(($this->applied)())->last()->custom)->toBe('client_max_body_size 50m;');
    });

    it('removes them again when the editor is emptied', function () {
        ($this->save)('gzip on;');
        ($this->save)("  \n")->assertSessionHasNoErrors();

        expect($this->shop->fresh()->nginx_custom)->toBeNull()
            ->and(collect(($this->applied)())->last()->custom)->toBeNull();
    });

    it('does nothing when nothing changed', function () {
        $this->shop->update(['nginx_custom' => 'gzip on;']);

        ($this->save)("gzip on;\n")->assertSessionHasNoErrors();

        expect($this->host->actionNames())->toBe([])
            ->and(AuditLog::query()->where('action', 'app.nginx_updated')->exists())->toBeFalse();
    });

    it('stores them without touching nginx while the app has no site', function () {
        Domain::query()->delete();

        ($this->save)('gzip on;')
            ->assertSessionHas('status', 'Saved. It is applied once the app has a domain and a live release.');

        expect($this->shop->fresh()->nginx_custom)->toBe('gzip on;')
            ->and($this->host->actionNames())->toBe(['checkNginxCustom', 'removeNginxSite']);
    });
});

describe('refusals', function () {
    it('saves nothing when the agent refuses the text, and says why', function () {
        $this->shop->update(['nginx_custom' => 'gzip on;']);
        $this->host->failOn('checkNginxCustom');

        ($this->save)('root /;')->assertSessionHasErrors('custom');

        expect($this->shop->fresh()->nginx_custom)->toBe('gzip on;')
            ->and($this->host->actionNames())->toBe([]) // the fake does not record the call it fails
            ->and(AuditLog::query()->where('action', 'app.nginx_updated')->exists())->toBeFalse();
    });

    it('saves nothing when nginx rejects the whole config, and the old one stays', function () {
        $this->shop->update(['nginx_custom' => 'gzip on;']);
        $this->host->failOn('applyNginxSite');

        ($this->save)('proxy_read_timeout 90s;')->assertSessionHasErrors('custom');

        expect($this->shop->fresh()->nginx_custom)->toBe('gzip on;')
            ->and(AuditLog::query()->where('action', 'app.nginx_updated')->exists())->toBeFalse();
    });

    it('shows the explanation without the transport prefix', function () {
        app()->instance(HostDriver::class, new class extends FakeHostDriver
        {
            public function checkNginxCustom(string $custom): void
            {
                throw new HostOperationFailed('Agent error: line 2: "root" is not supported.');
            }
        });

        ($this->save)("gzip on;\nroot /;")->assertSessionHasErrors(['custom' => 'line 2: "root" is not supported.']);
    });

    it('refuses text that is too long before asking the agent', function () {
        ($this->save)(str_repeat('# x'."\n", 6000))->assertSessionHasErrors('custom');

        expect($this->host->actionNames())->toBe([]);
    });

    it('needs a recent password confirmation', function () {
        $this->put('/apps/shop/nginx', ['custom' => 'gzip on;'])->assertRedirect('/user/confirm-password');

        expect($this->shop->fresh()->nginx_custom)->toBeNull()
            ->and($this->host->actionNames())->toBe([]);
    });
});
