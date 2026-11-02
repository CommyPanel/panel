<?php

use App\Backups\BackupFailed;
use App\Backups\PanelBackups;
use App\Models\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * These use a real SQLite file, because the point is that VACUUM INTO and the
 * restore work on one (the feature tests run on an in-memory database).
 */
beforeEach(function () {
    $this->dir = storage_path('framework/testing/backup-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir.'/db');

    config([
        'database.connections.main.database' => $this->dir.'/db/main.sqlite',
        'commypanel.data_path' => $this->dir,
        'commypanel.backups.keep' => 3,
    ]);
    DB::purge('main');

    touch($this->dir.'/db/main.sqlite');
    Artisan::call('migrate', ['--database' => 'main', '--force' => true]);

    App::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $this->backups = app(PanelBackups::class);
});

afterEach(function () {
    DB::purge('main');
    File::deleteDirectory($this->dir);
});

it('writes a compressed backup that only the panel user can read', function () {
    $backup = $this->backups->create();

    $path = $this->dir.'/backups/panel/'.$backup['name'];

    expect($backup['name'])->toMatch('/^panel-\d{8}-\d{6}\.sqlite\.gz$/')
        ->and($backup['size'])->toBeGreaterThan(0)
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(decoct(fileperms($this->dir.'/backups/panel') & 0777))->toBe('700')
        ->and(file_get_contents($path, false, null, 0, 2))->toBe("\x1f\x8b")
        ->and(glob($this->dir.'/backups/panel/.snapshot-*'))->toBe([]);
});

it('holds the data as it was when the backup was made', function () {
    $backup = $this->backups->create();

    $pdo = new PDO('sqlite::memory:');
    $copy = tempnam(sys_get_temp_dir(), 'bk');
    file_put_contents($copy, gzdecode((string) file_get_contents($this->dir.'/backups/panel/'.$backup['name'])));
    $pdo = new PDO('sqlite:'.$copy);

    expect($pdo->query('SELECT slug FROM apps')->fetchAll(PDO::FETCH_COLUMN))->toBe(['shop'])
        ->and($pdo->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');

    $pdo = null;
    unlink($copy);
});

it('restores the database, and keeps what was there as a backup of its own', function () {
    $backup = $this->backups->create();

    App::query()->delete();
    App::factory()->create(['name' => 'Later', 'slug' => 'later']);
    expect(App::query()->pluck('slug')->all())->toBe(['later']);

    $this->backups->restore($this->dir.'/backups/panel/'.$backup['name']);
    DB::purge('main');

    expect(App::query()->pluck('slug')->all())->toBe(['shop'])
        ->and(collect($this->backups->all())->pluck('name')->contains(fn ($n) => str_ends_with($n, '-before.sqlite.gz')))->toBeTrue();

    // The state just before the restore can be brought back too.
    $before = collect($this->backups->all())->first(fn ($b) => str_ends_with($b['name'], '-before.sqlite.gz'));
    $this->backups->restore($this->dir.'/backups/panel/'.$before['name']);
    DB::purge('main');

    expect(App::query()->pluck('slug')->all())->toBe(['later']);
});

it('leaves no write-ahead log of the old database behind after a restore', function () {
    $backup = $this->backups->create();
    file_put_contents($this->dir.'/db/main.sqlite-wal', 'stale');
    file_put_contents($this->dir.'/db/main.sqlite-shm', 'stale');

    $this->backups->restore($this->dir.'/backups/panel/'.$backup['name']);

    expect(file_exists($this->dir.'/db/main.sqlite-wal'))->toBeFalse()
        ->and(file_exists($this->dir.'/db/main.sqlite-shm'))->toBeFalse()
        ->and(decoct(fileperms($this->dir.'/db/main.sqlite') & 0777))->toBe('600');
});

it('refuses a file that is not a backup, and changes nothing', function (string $kind) {
    $file = $this->dir.'/candidate.sqlite.gz';

    match ($kind) {
        'not compressed' => file_put_contents($file, 'plain text'),
        'compressed text' => file_put_contents($file, gzencode('this is not sqlite')),
        'another database' => (function () use ($file) {
            $raw = $this->dir.'/other.sqlite';
            $pdo = new PDO('sqlite:'.$raw);
            $pdo->exec('CREATE TABLE things (id INTEGER)');
            $pdo = null;
            file_put_contents($file, gzencode((string) file_get_contents($raw)));
        })(),
        'missing' => null,
    };

    expect(fn () => $this->backups->restore($file))->toThrow(BackupFailed::class);
    expect(App::query()->pluck('slug')->all())->toBe(['shop'])
        ->and(glob($this->dir.'/db/.restore-*'))->toBe([]);
})->with(['not compressed', 'compressed text', 'another database', 'missing']);

it('only lets a backup be named by its own kind of file name', function (string $name) {
    $this->backups->create();

    expect(fn () => $this->backups->path($name))->toThrow(BackupFailed::class);
})->with(['traversal' => '../../db/main.sqlite', 'a path' => 'panel-20260101-000000.sqlite.gz/../x', 'another file' => 'main.sqlite', 'empty' => '', 'a hidden temporary' => '.snapshot-abcd1234.sqlite']);

it('keeps only the newest backups', function () {
    $dir = $this->dir.'/backups/panel';
    File::ensureDirectoryExists($dir);

    foreach (range(1, 6) as $i) {
        $name = sprintf('panel-2026010%d-000000.sqlite.gz', $i);
        file_put_contents($dir.'/'.$name, gzencode('x'));
        touch($dir.'/'.$name, 1_700_000_000 + $i * 100);
    }

    expect($this->backups->prune(3))->toBe(3)
        ->and(collect($this->backups->all())->pluck('name')->all())->toBe(['panel-20260106-000000.sqlite.gz', 'panel-20260105-000000.sqlite.gz', 'panel-20260104-000000.sqlite.gz']);

    // Taking one more also trims, using the configured number.
    $this->backups->create();
    expect($this->backups->all())->toHaveCount(3);
});

it('deletes a backup by name', function () {
    $backup = $this->backups->create();

    $this->backups->delete($backup['name']);

    expect($this->backups->all())->toBe([]);
});

it('is done by the backup command, and restored by the restore command only when told the services are stopped', function () {
    Artisan::call('commypanel:backup');
    $name = $this->backups->all()[0]['name'];

    App::query()->delete();

    expect(Artisan::call('commypanel:restore', ['file' => $name]))->toBe(1);
    expect(App::query()->count())->toBe(0);

    expect(Artisan::call('commypanel:restore', ['file' => $name, '--force' => true]))->toBe(0);
    DB::purge('main');
    expect(App::query()->pluck('slug')->all())->toBe(['shop']);

    expect(Artisan::call('commypanel:restore', ['file' => 'panel-19990101-000000.sqlite.gz', '--force' => true]))->toBe(1);
});
