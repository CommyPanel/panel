<?php

use App\Deploy\DestroyApp;
use App\Deploy\LockedException;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppVolume;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'container_name' => 'shop-1']);
});

describe('locking an app', function () {
    it('locks and unlocks, and records both', function () {
        $this->put('/apps/shop/lock', ['locked' => true])->assertSessionHas('status');
        expect($this->shop->fresh()->isLocked())->toBeTrue();

        $this->put('/apps/shop/lock', ['locked' => false])->assertSessionHas('status');
        expect($this->shop->fresh()->isLocked())->toBeFalse()
            ->and(AuditLog::query()->whereIn('action', ['app.locked', 'app.unlocked'])->orderBy('id')->pluck('action')->all())->toBe(['app.locked', 'app.unlocked']);
    });

    it('does nothing, and writes nothing, when the state is already what was asked for', function () {
        $this->put('/apps/shop/lock', ['locked' => false])->assertRedirect();

        expect(AuditLog::query()->where('action', 'app.unlocked')->count())->toBe(0);
    });

    it('needs a yes or a no', function () {
        $this->put('/apps/shop/lock', [])->assertSessionHasErrors('locked');
        $this->put('/apps/shop/lock', ['locked' => 'maybe'])->assertSessionHasErrors('locked');
    });

    it('stops the app from being deleted, touching nothing on the host', function () {
        $this->shop->update(['locked_at' => now()]);

        $this->delete('/apps/shop', ['confirm' => 'shop'])->assertSessionHasErrors(['confirm' => 'App is locked and cannot be deleted. Unlock it first.']);

        expect(App::query()->count())->toBe(1)
            ->and($this->host->actionNames())->not->toContain('removeContainer')
            ->and(AuditLog::query()->where('action', 'app.deleted')->count())->toBe(0);
    });

    it('lets it be deleted again once unlocked', function () {
        $this->shop->update(['locked_at' => now()]);
        $this->put('/apps/shop/lock', ['locked' => false]);

        $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

        expect(App::query()->count())->toBe(0);
    });

    it('is enforced below the controller too', function () {
        $this->shop->update(['locked_at' => now()]);

        expect(fn () => app(DestroyApp::class)($this->shop))->toThrow(LockedException::class, 'App is locked');
        expect($this->host->actionNames())->toBe([]);
    });

    it('is shown to the pages', function () {
        $this->shop->update(['locked_at' => now()]);

        $this->get('/apps')->assertInertia(fn (Assert $page) => $page->where('apps.0.locked', true));
        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.locked', true));
    });
});

describe('locking a volume', function () {
    beforeEach(function () {
        $this->volume = $this->shop->volumes()->create(['name' => 'uploads', 'path' => '/app/uploads']);
    });

    it('locks and unlocks, and records both', function () {
        $this->put("/apps/shop/volumes/{$this->volume->id}/lock", ['locked' => true])->assertSessionHas('status');
        expect($this->volume->fresh()->isLocked())->toBeTrue();

        $this->put("/apps/shop/volumes/{$this->volume->id}/lock", ['locked' => false]);
        expect($this->volume->fresh()->isLocked())->toBeFalse()
            ->and(AuditLog::query()->whereIn('action', ['app.volume_locked', 'app.volume_unlocked'])->count())->toBe(2);
    });

    it('cannot be removed from the app while locked', function () {
        $this->volume->update(['locked_at' => now()]);

        $this->delete("/apps/shop/volumes/{$this->volume->id}")->assertSessionHasErrors('volume');

        expect(AppVolume::query()->count())->toBe(1);

        $this->put("/apps/shop/volumes/{$this->volume->id}/lock", ['locked' => false]);
        $this->delete("/apps/shop/volumes/{$this->volume->id}")->assertSessionHas('status');

        expect(AppVolume::query()->count())->toBe(0);
    });

    it('protects the app from deletion too, because deleting the app deletes the files', function () {
        $this->volume->update(['locked_at' => now()]);

        $this->delete('/apps/shop', ['confirm' => 'shop'])->assertSessionHasErrors('confirm');

        expect(App::query()->count())->toBe(1)
            ->and(session('errors')->get('confirm')[0])->toContain('/app/uploads')->toContain('Unlock the volume first')
            ->and($this->host->actionNames())->not->toContain('removeVolumes');
    });

    it('is shown to the page', function () {
        $this->volume->update(['locked_at' => now()]);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.volumes.0.locked', true));
    });

    it('cannot be locked through another app\'s address', function () {
        $other = App::factory()->create(['slug' => 'blog']);

        $this->put("/apps/blog/volumes/{$this->volume->id}/lock", ['locked' => true])->assertNotFound();

        expect($this->volume->fresh()->isLocked())->toBeFalse();
    });
});
