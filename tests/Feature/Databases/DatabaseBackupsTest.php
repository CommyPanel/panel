<?php

use App\Databases\DatabaseBackups;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\AuditLog;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/dbbackups-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dir, 'commypanel.backups.keep_databases' => 3]);
    Sleep::fake();

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
    $this->shop = app(ManageDatabases::class)->create($this->server, 'shop');
    $this->blog = app(ManageDatabases::class)->create($this->server, 'blog');
});

afterEach(fn () => File::deleteDirectory($this->dir));

describe('making a dump', function () {
    it('writes a compressed file that only the panel user can read', function () {
        $backup = app(DatabaseBackups::class)->create($this->shop);

        $path = $this->dir.'/backups/databases/main/'.$backup['name'];

        expect($backup['name'])->toMatch('/^shop-\d{8}-\d{6}\.sql\.gz$/')
            ->and($backup['database'])->toBe('shop')
            ->and(gzdecode((string) file_get_contents($path)))->toBe("-- fake dump of shop\n")
            ->and(decoct(fileperms($path) & 0777))->toBe('600')
            ->and(decoct(fileperms(dirname($path)) & 0777))->toBe('700');
    });

    it('needs a running server', function () {
        app(DatabaseServers::class)->stop($this->server);

        expect(fn () => app(DatabaseBackups::class)->create($this->shop->fresh('server')))->toThrow(DatabaseException::class, 'not running');
    });

    it('says why it failed, and keeps no file', function () {
        $this->host->failOn('dumpDatabase');

        expect(fn () => app(DatabaseBackups::class)->create($this->shop))->toThrow(DatabaseException::class, 'Could not back up shop');
        expect(app(DatabaseBackups::class)->all($this->server))->toBe([]);
    });

    it('keeps the newest few of each database, and counts each database on its own', function () {
        $dir = $this->dir.'/backups/databases/main';
        File::ensureDirectoryExists($dir);

        foreach (range(1, 5) as $i) {
            foreach (['shop', 'blog'] as $name) {
                $file = sprintf('%s/%s-2026010%d-000000.sql.gz', $dir, $name, $i);
                file_put_contents($file, gzencode('x'));
                touch($file, 1_700_000_000 + $i * 100);
            }
        }

        app(DatabaseBackups::class)->create($this->shop);

        $names = collect(app(DatabaseBackups::class)->all($this->server))->groupBy('database')->map->count();

        expect($names['shop'])->toBe(3)->and($names['blog'])->toBe(5);
    });

    it('only names files of its own kind', function (string $name) {
        app(DatabaseBackups::class)->create($this->shop);

        expect(fn () => app(DatabaseBackups::class)->path($this->server, $name))->toThrow(DatabaseException::class);
    })->with(['traversal' => '../../main.sqlite', 'a path' => 'shop-20260101-000000.sql.gz/../x', 'wrong extension' => 'shop.sql', 'upper case' => 'Shop-20260101-000000.sql.gz', 'empty' => '']);
});

describe('the nightly job', function () {
    it('dumps every database on every running server, and skips stopped ones', function () {
        $other = app(DatabaseServers::class)->create('Second', DatabaseEngine::Mariadb, '11.4')->fresh();
        app(ManageDatabases::class)->create($other, 'wiki');
        app(DatabaseServers::class)->stop($other);

        $code = Artisan::call('commypanel:backup-databases');

        expect($code)->toBe(0)
            ->and(collect(app(DatabaseBackups::class)->all($this->server))->pluck('database')->sort()->values()->all())->toBe(['blog', 'shop'])
            ->and(app(DatabaseBackups::class)->all($other))->toBe([])
            ->and(Artisan::output())->toContain('2 database backup(s) made');
    });

    it('goes on after one fails and reports it', function () {
        $this->host->failOn('dumpDatabase');

        $code = Artisan::call('commypanel:backup-databases');

        expect($code)->toBe(1)
            ->and(Artisan::output())->toContain('1 database backup(s) made, 1 failed');
    });

    it('runs every night', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toContain('commypanel:backup-databases');
    });
});

describe('in the panel', function () {
    it('backs one up by hand and records it', function () {
        $this->post("/databases/main/databases/{$this->shop->id}/backup")->assertSessionHas('status');

        expect(app(DatabaseBackups::class)->all($this->server))->toHaveCount(1)
            ->and(AuditLog::query()->where('action', 'database.backed_up')->first()->metadata)->toMatchArray(['server' => 'main', 'database' => 'shop']);
    });

    it('lists them on the server page', function () {
        app(DatabaseBackups::class)->create($this->shop);

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->has('backups', 1)
            ->where('backups.0.database', 'shop')
            ->where('keep', 3));
    });

    it('downloads one only after the password, and records it', function () {
        $name = app(DatabaseBackups::class)->create($this->shop)['name'];

        $this->get("/databases/main/backups/{$name}")->assertRedirect();
        expect(AuditLog::query()->where('action', 'database.backup_downloaded')->count())->toBe(0);

        $response = ($this->confirmed)()->get("/databases/main/backups/{$name}")->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain($name)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(AuditLog::query()->where('action', 'database.backup_downloaded')->first()->metadata)->toBe(['server' => 'main', 'file' => $name]);
    });

    it('deletes one after the password', function () {
        $name = app(DatabaseBackups::class)->create($this->shop)['name'];

        ($this->confirmed)()->delete("/databases/main/backups/{$name}")->assertSessionHas('status');

        expect(app(DatabaseBackups::class)->all($this->server))->toBe([]);
    });

    it('cannot reach another server\'s dumps through this server\'s address', function () {
        $name = app(DatabaseBackups::class)->create($this->shop)['name'];
        app(DatabaseServers::class)->create('Other', DatabaseEngine::Mariadb, '11.4');

        ($this->confirmed)()->get("/databases/other/backups/{$name}")->assertSessionHasErrors('database');
    });

    it('is deleted with its server', function () {
        app(DatabaseBackups::class)->create($this->shop);
        // Databases that no app uses can go with the server.
        expect(is_dir($this->dir.'/backups/databases/main'))->toBeTrue();

        app(DatabaseServers::class)->delete(DatabaseServer::query()->where('slug', 'main')->firstOrFail());

        expect(is_dir($this->dir.'/backups/databases/main'))->toBeFalse();
    });
});
