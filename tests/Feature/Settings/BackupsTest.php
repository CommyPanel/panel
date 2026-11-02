<?php

use App\Backups\BackupFailed;
use App\Backups\DatabaseSnapshotter;
use App\Backups\PanelBackups;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dir, 'commypanel.backups.keep' => 5]);

    // The feature tests run on an in-memory database, which VACUUM INTO cannot copy: a stand-in
    // writes a small but valid panel database instead (the real thing is in the integration tests).
    app()->bind(DatabaseSnapshotter::class, fn () => new class implements DatabaseSnapshotter
    {
        public function snapshot(string $toPath): void
        {
            $pdo = new PDO('sqlite:'.$toPath);
            $pdo->exec('CREATE TABLE migrations (id INTEGER); CREATE TABLE users (id INTEGER); CREATE TABLE apps (id INTEGER)');
        }

        public function livePath(): ?string
        {
            return null;
        }
    });

    $this->actingAs(User::factory()->create());
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('lists the backups, newest first, with the folder and the number kept', function () {
    app(PanelBackups::class)->create();
    $dir = $this->dir.'/backups/panel';
    file_put_contents($dir.'/panel-20200101-000000.sqlite.gz', gzencode('old'));
    touch($dir.'/panel-20200101-000000.sqlite.gz', 1_577_836_800);

    $this->get('/settings/backups')->assertInertia(fn (Assert $page) => $page
        ->component('settings/backups')
        ->has('backups', 2)
        ->where('backups.1.name', 'panel-20200101-000000.sqlite.gz')
        ->where('backups.1.created_at', fn ($v) => str_starts_with($v, '2020-01-01'))
        ->where('keep', 5)
        ->where('folder', $dir));
});

it('takes a backup now and records it', function () {
    $this->post('/settings/backups')->assertSessionHas('status');

    $all = app(PanelBackups::class)->all();

    expect($all)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'backup.created')->first()->metadata)->toBe(['name' => $all[0]['name']]);
});

it('says why a backup could not be made', function () {
    app()->bind(DatabaseSnapshotter::class, fn () => new class implements DatabaseSnapshotter
    {
        public function snapshot(string $toPath): void
        {
            throw new BackupFailed('The disk is full.');
        }

        public function livePath(): ?string
        {
            return null;
        }
    });

    $this->post('/settings/backups')->assertSessionHasErrors(['backup' => 'The disk is full.']);

    expect(app(PanelBackups::class)->all())->toBe([]);
});

it('downloads a backup only after the password is confirmed, and records it', function () {
    $name = app(PanelBackups::class)->create()['name'];

    $this->get("/settings/backups/{$name}")->assertRedirect();
    expect(AuditLog::query()->where('action', 'backup.downloaded')->count())->toBe(0);

    $response = ($this->confirmed)()->get("/settings/backups/{$name}")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain($name)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(AuditLog::query()->where('action', 'backup.downloaded')->first()->metadata)->toBe(['name' => $name]);
});

it('deletes a backup after the password is confirmed', function () {
    $name = app(PanelBackups::class)->create()['name'];

    $this->delete("/settings/backups/{$name}")->assertRedirect();
    expect(app(PanelBackups::class)->all())->toHaveCount(1);

    ($this->confirmed)()->delete("/settings/backups/{$name}")->assertSessionHas('status');

    expect(app(PanelBackups::class)->all())->toBe([])
        ->and(AuditLog::query()->where('action', 'backup.deleted')->count())->toBe(1);
});

it('cannot name a file that is not a backup', function (string $name) {
    app(PanelBackups::class)->create();
    file_put_contents($this->dir.'/secret.txt', 'secret');

    $download = ($this->confirmed)()->get('/settings/backups/'.$name);
    $delete = ($this->confirmed)()->delete('/settings/backups/'.$name);

    // Either the route does not match (404) or the panel answers "no such backup"; never the file.
    expect($download->status())->toBeIn([302, 404])
        ->and($download->headers->get('Content-Disposition'))->toBeNull()
        ->and($delete->status())->toBeIn([302, 404])
        ->and(file_exists($this->dir.'/secret.txt'))->toBeTrue()
        ->and(AuditLog::query()->whereIn('action', ['backup.downloaded', 'backup.deleted'])->count())->toBe(0);
})->with(['a plain file' => 'secret.txt', 'dots' => '..', 'encoded slash' => '..%2f..%2fsecret.txt']);

it('refuses a name that matches the route but is not a backup', function () {
    ($this->confirmed)()->get('/settings/backups/main.sqlite')->assertSessionHasErrors('backup');
    ($this->confirmed)()->get('/settings/backups/panel-20260101-000000.sqlite.gz')->assertSessionHasErrors('backup');
});
