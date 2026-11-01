<?php

use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $this->shop->volumes()->create(['name' => 'uploads', 'path' => '/app/uploads']);
    $this->shop->volumes()->create(['name' => 'cache', 'path' => '/app/cache']);

    $this->host
        ->putVolumeFile('shop', 'uploads', 'readme.txt', "hello\n")
        ->putVolumeFile('shop', 'uploads', 'photos/cat.jpg', "\xff\xd8\0binary")
        ->putVolumeFile('shop', 'uploads', 'photos/2026/new.txt', 'deep')
        ->putVolumeFile('shop', 'old-data', 'db.sqlite', str_repeat('x', 1000));
});

describe('the overview', function () {
    it('lists attached volumes with their size, and data that is no longer attached', function () {
        $json = $this->getJson('/apps/shop/volumes')->assertOk()->json();

        expect($json['total_bytes'])->toBe(6 + 9 + 4 + 1000)
            ->and($json['running'])->toBeFalse()
            ->and($json['volumes'])->toHaveCount(3)
            ->and($json['volumes'][0])->toMatchArray(['name' => 'uploads', 'path' => '/app/uploads', 'attached' => true, 'exists' => true, 'bytes' => 19, 'files' => 3, 'locked' => false])
            ->and($json['volumes'][0]['id'])->toBeInt()
            ->and($json['volumes'][2]['id'])->toBeNull()
            // Attached, but no container has used it yet, so there is no folder.
            ->and($json['volumes'][1])->toMatchArray(['name' => 'cache', 'attached' => true, 'exists' => false, 'bytes' => 0])
            ->and($json['volumes'][2])->toMatchArray(['name' => 'old-data', 'path' => null, 'attached' => false, 'exists' => true, 'bytes' => 1000]);
    });

    it('does not mix in another app\'s volumes', function () {
        $this->host->putVolumeFile('blog', 'media', 'x', 'secret of another app');
        App::factory()->create(['slug' => 'blog']);

        expect(collect($this->getJson('/apps/shop/volumes')->json('volumes'))->pluck('name')->all())->not->toContain('media');
    });

    it('answers 502 when the agent cannot be reached', function () {
        $this->host->failOn('listVolumes');

        $this->getJson('/apps/shop/volumes')->assertStatus(502)->assertJsonStructure(['error']);
    });
});

describe('browsing', function () {
    it('shows the volume page with its folder, folders first', function () {
        $this->get('/apps/shop/volumes/uploads')->assertInertia(fn (Assert $page) => $page
            ->component('apps/volume')
            ->where('app.slug', 'shop')
            ->where('volume.name', 'uploads')
            ->where('volume.path', '/app/uploads')
            ->where('volume.attached', true)
            ->where('volume.locked', false)
            ->where('volume.bytes', 19)
            ->where('volume.files', 3)
            ->where('path', '')
            ->where('entries.0.kind', 'dir')
            ->where('entries.0.name', 'photos')
            ->where('entries.1.name', 'readme.txt')
            ->where('folder', ['files' => 1, 'folders' => 1, 'other' => 0, 'truncated' => false])
            ->where('error', null));
    });

    it('shows a subfolder and its own counts', function () {
        $this->get('/apps/shop/volumes/uploads?path=photos')->assertInertia(fn (Assert $page) => $page
            ->where('path', 'photos')
            ->where('entries.0.name', '2026')
            ->where('entries.1.name', 'cat.jpg')
            ->where('folder.files', 1)
            ->where('folder.folders', 1));
    });

    it('trims slashes from the path it passes on', function () {
        $this->get('/apps/shop/volumes/uploads?path=/photos/')->assertOk();

        expect(collect($this->host->actions())->firstWhere('action', 'browseVolume')['args']['path'])->toBe('photos');
    });

    it('shows a volume that is no longer attached, as not attached', function () {
        $this->get('/apps/shop/volumes/old-data')->assertInertia(fn (Assert $page) => $page
            ->where('volume.attached', false)
            ->where('volume.path', null)
            ->where('volume.bytes', 1000)
            ->where('entries.0.name', 'db.sqlite'));
    });

    it('shows an attached volume that has no folder yet, without asking the agent for it', function () {
        $this->get('/apps/shop/volumes/cache')->assertInertia(fn (Assert $page) => $page
            ->where('volume.exists', false)
            ->where('entries', [])
            ->where('error', null));

        expect($this->host->actionNames())->not->toContain('browseVolume');
    });

    it('does not know a name that is neither attached nor on disk', function () {
        $this->get('/apps/shop/volumes/nothing-here')->assertNotFound();
    });

    it('previews a text file and says when a file is binary', function () {
        $this->getJson('/apps/shop/volumes/uploads/file?path=readme.txt')->assertOk()
            ->assertJson(['content' => "hello\n", 'binary' => false, 'size' => 6, 'truncated' => false]);

        $this->getJson('/apps/shop/volumes/uploads/file?path=photos/cat.jpg')->assertOk()
            ->assertJson(['content' => '', 'binary' => true]);
    });

    it('shows a missing folder as an error on the page, and a missing file as an error answer', function () {
        $this->get('/apps/shop/volumes/uploads?path=nope')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('entries', [])
            ->where('error', fn ($error) => str_contains($error, 'does not exist')));

        $this->getJson('/apps/shop/volumes/uploads/file?path=nope.txt')->assertStatus(502)->assertJsonStructure(['error']);
    });

    it('still shows the page, with the reason, when the agent cannot be reached', function () {
        $this->host->failOn('listVolumes');

        $this->get('/apps/shop/volumes/uploads')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('volume.attached', true)
            ->where('volume.exists', false)
            ->where('error', fn ($error) => $error !== null));
    });

    it('refuses a volume name that is not a folder name', function (string $name) {
        $this->get("/apps/shop/volumes/{$name}")->assertNotFound();
    })->with(['capitals' => 'Uploads', 'dots' => '..', 'underscore' => 'a_b', 'dash first' => '-x']);

    it('downloads a file as an attachment and records it in the audit log', function () {
        $response = $this->get('/apps/shop/volumes/uploads/download?path=photos/cat.jpg')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="cat.jpg"')
            ->and($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->getContent())->toBe("\xff\xd8\0binary")
            ->and(AuditLog::query()->where('action', 'app.volume_file_downloaded')->first()->metadata)
            ->toMatchArray(['app' => 'shop', 'volume' => 'uploads', 'path' => 'photos/cat.jpg']);
    });

    it('cleans an odd file name before putting it in a header', function () {
        $this->host->putVolumeFile('shop', 'uploads', "my \"weird\"\r\nname.txt", 'x');

        $response = $this->get('/apps/shop/volumes/uploads/download?path='.rawurlencode("my \"weird\"\r\nname.txt"))->assertOk();

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="my_weird_name.txt"');
    });

    it('does not let a download that failed leave an audit entry', function () {
        $this->get('/apps/shop/volumes/uploads/download?path=missing.txt')->assertStatus(502);

        expect(AuditLog::query()->where('action', 'app.volume_file_downloaded')->count())->toBe(0);
    });
});

describe('deleting the data of a volume that is no longer attached', function () {
    it('asks for the password first', function () {
        $this->delete('/apps/shop/volumes/old-data/data')->assertRedirect();

        expect($this->host->actionNames())->not->toContain('removeVolumes');
    });

    it('deletes it, records it, and leaves the other volumes', function () {
        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/apps/shop/volumes/old-data/data')->assertSessionHas('status');

        $removed = collect($this->host->actions())->firstWhere('action', 'removeVolumes');

        expect($removed['args'])->toBe(['app' => 'shop', 'name' => 'old-data'])
            ->and(AuditLog::query()->where('action', 'app.volume_data_deleted')->count())->toBe(1);
    });

    it('refuses while the volume is still attached to the app', function () {
        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/apps/shop/volumes/uploads/data')->assertSessionHasErrors('volume');

        expect($this->host->actionNames())->not->toContain('removeVolumes');
    });

    it('shows the agent\'s refusal, such as a container still mounting it', function () {
        $this->host->failOn('removeVolumes');

        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/apps/shop/volumes/old-data/data')->assertSessionHasErrors('volume');

        expect(AuditLog::query()->where('action', 'app.volume_data_deleted')->count())->toBe(0);
    });
});
