<?php

use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Alerts\BackupAlerts;
use App\Alerts\CertificateAlerts;
use App\Alerts\Monitor;
use App\Audit\AuditLogger;
use App\Backups\Remote\RemoteBackupFailed;
use App\Backups\VolumeBackups;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Deploy\Health\HealthChecker;
use App\Enums\CertificateStatus;
use App\Enums\DatabaseEngine;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Host\HostHealth;
use App\Jobs\BackupVolumeJob;
use App\Jobs\SendAlertJob;
use App\Jobs\UploadBackupJob;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Domain;
use App\Tls\HttpsSettings;
use App\Tls\IssueCertificate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeHealthChecker;

const ALERT_MON_GB = 1024 ** 3;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-11-09 10:00:00');
    Cache::flush();
    AlertTarget::query()->create(['name' => 'Chat', 'kind' => AlertKind::Slack, 'address' => 'https://hooks.example.com/x', 'events' => AlertEvent::keys()]);
    $this->health = new FakeHealthChecker(true);
    app()->instance(HealthChecker::class, $this->health);
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop', 'health_path' => '/up']);
    $this->live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001]);
    $this->shop->update(['active_deployment_id' => $this->live->id]);

    $this->look = fn () => app(Monitor::class)->run();
    $this->sent = fn (): array => Queue::pushed(SendAlertJob::class)->map(fn ($j) => $j->message->event->value)->values()->all();
});

describe('apps', function () {
    it('is down after three failed minutes in a row, not before, and tells it once', function () {
        $this->health->healthy = false;

        ($this->look)();
        Carbon::setTestNow(now()->addMinute());
        ($this->look)();
        expect(($this->sent)())->toBe([]);

        Carbon::setTestNow(now()->addMinute());
        ($this->look)();
        expect(($this->sent)())->toBe(['app.down']);
        expect($this->health->calls[0])->toBe(['port' => 20001, 'path' => '/up']);

        foreach (range(1, 5) as $_) {
            Carbon::setTestNow(now()->addMinute());
            ($this->look)();
        }
        expect(($this->sent)())->toBe(['app.down']);
    });

    it('starts counting again after a good look', function () {
        $this->health->healthy = false;
        ($this->look)();
        ($this->look)();
        $this->health->healthy = true;
        ($this->look)();
        $this->health->healthy = false;
        ($this->look)();
        ($this->look)();

        expect(($this->sent)())->toBe([]);
    });

    it('tells when it answers again, with how long it was down', function () {
        $this->health->healthy = false;
        foreach (range(1, 3) as $_) {
            ($this->look)();
            Carbon::setTestNow(now()->addMinute());
        }
        Carbon::setTestNow(now()->addMinutes(7));
        $this->health->healthy = true;
        ($this->look)();

        expect(($this->sent)())->toBe(['app.down', 'app.recovered']);
        $recovered = Queue::pushed(SendAlertJob::class)->last()->message;
        expect($recovered->lines[0])->toContain('It was down for 10 minutes')->and($recovered->app)->toBe('shop');

        ($this->look)();
        expect(($this->sent)())->toHaveCount(2);
    });

    it('does not look at an app that is stopped, being deployed, watched or rolled out', function (callable $setup) {
        $setup($this);
        $this->health->healthy = false;

        foreach (range(1, 5) as $_) {
            ($this->look)();
        }

        expect(($this->sent)())->toBe([])->and($this->health->calls)->toBe([]);
    })->with([
        'stopped' => [fn ($t) => $t->shop->update(['stopped_at' => now()])],
        'deploying' => [fn ($t) => Deployment::factory()->create(['app_id' => $t->shop->id, 'number' => 2, 'status' => DeploymentStatus::Building])],
        'queued' => [fn ($t) => Deployment::factory()->create(['app_id' => $t->shop->id, 'number' => 2, 'status' => DeploymentStatus::Queued])],
        'rolling out' => [fn ($t) => Deployment::factory()->create(['app_id' => $t->shop->id, 'number' => 2, 'status' => DeploymentStatus::Rollout])],
        'watching' => [fn ($t) => $t->live->update(['watch_state' => 'watching'])],
        'never deployed' => [fn ($t) => $t->shop->update(['active_deployment_id' => null])],
    ]);

    it('forgets a count when a deployment starts, so a deploy never adds up to "down"', function () {
        $this->health->healthy = false;
        ($this->look)();
        ($this->look)();
        $deploying = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Building]);
        ($this->look)();
        $deploying->update(['status' => DeploymentStatus::Failed]);
        ($this->look)();

        expect(($this->sent)())->toBe([]);
    });

    it('says nothing when the app is stopped while it was down', function () {
        $this->health->healthy = false;
        foreach (range(1, 3) as $_) {
            ($this->look)();
        }
        $this->shop->update(['stopped_at' => now()]);
        ($this->look)();
        $this->shop->update(['stopped_at' => null]);
        $this->health->healthy = true;
        ($this->look)();

        expect(($this->sent)())->toBe(['app.down']);
    });

    it('stays quiet for an app whose alerts are muted', function () {
        $this->shop->update(['alerts_enabled' => false]);
        $this->health->healthy = false;

        foreach (range(1, 4) as $_) {
            ($this->look)();
        }

        expect(($this->sent)())->toBe([]);
    });

    it('runs every minute from the scheduler', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toContain('commypanel:monitor');
        expect(Artisan::call('commypanel:monitor'))->toBe(0);
    });
});

describe('database servers', function () {
    beforeEach(function () {
        Queue::fake([SendAlertJob::class]); // provisioning runs at once
        $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
        expect($this->server->isRunning())->toBeTrue();
    });

    it('is stopped after two looks without its container, and running again after one with it', function () {
        ($this->look)();
        expect(($this->sent)())->toBe([]);

        $this->host->stopContainer('db-main');
        ($this->look)();
        expect(($this->sent)())->toBe([]);
        ($this->look)();
        ($this->look)();
        expect(($this->sent)())->toBe(['database.stopped']);

        $this->host->startContainer('db-main');
        Carbon::setTestNow(now()->addMinutes(5));
        ($this->look)();
        ($this->look)();

        expect(($this->sent)())->toBe(['database.stopped', 'database.recovered']);
    });

    it('does not count a server that is not meant to be running', function () {
        $this->host->stopContainer('db-main');
        $this->server->update(['status' => 'stopped']);

        foreach (range(1, 4) as $_) {
            ($this->look)();
        }

        expect(($this->sent)())->toBe([]);
    });
});

describe('the disk', function () {
    beforeEach(function () {
        $this->disk = function (int $usedPercent) {
            $this->host->setDiskFree((100 - $usedPercent) * ALERT_MON_GB);
            app(HostHealth::class)->forget();
        };
    });

    it('alerts once when a level is crossed upwards, never again while it stays', function () {
        ($this->disk)(70);
        ($this->look)();
        ($this->disk)(86);
        ($this->look)();
        ($this->look)();
        ($this->disk)(90);
        ($this->look)();
        ($this->disk)(96);
        ($this->look)();
        ($this->look)();

        expect(($this->sent)())->toBe(['disk.warning', 'disk.critical']);
    });

    it('sends only the critical alert when it jumps over the warning', function () {
        ($this->disk)(97);
        ($this->look)();

        expect(($this->sent)())->toBe(['disk.critical']);
    });

    it('recovers once, only with a few points of room, and is quiet while hovering', function () {
        ($this->disk)(86);
        ($this->look)();
        ($this->disk)(84);
        ($this->look)();
        ($this->disk)(86);
        ($this->look)();
        ($this->disk)(83);
        ($this->look)();
        expect(($this->sent)())->toBe(['disk.warning']);

        ($this->disk)(81);
        ($this->look)();
        ($this->look)();

        expect(($this->sent)())->toBe(['disk.warning', 'disk.recovered']);
    });

    it('falls from critical to warning quietly, then recovers', function () {
        ($this->disk)(96);
        ($this->look)();
        ($this->disk)(88);
        ($this->look)();
        ($this->disk)(50);
        ($this->look)();

        expect(($this->sent)())->toBe(['disk.critical', 'disk.recovered']);
    });
});

describe('certificates', function () {
    beforeEach(function () {
        config(['commypanel.https.staging' => true]);
        app(HttpsSettings::class)->enable('ops@example.com');
        $this->domain = Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id, 'status' => DomainStatus::Verified]);
        $this->failures = fn () => collect(($this->sent)())->filter(fn ($e) => $e === 'certificate.failed')->count();
    });

    it('raises certificate.failed when issuing fails, with the reason and the app', function () {
        $this->host->failOn('issueCertificate');

        app(IssueCertificate::class)($this->shop);

        $message = Queue::pushed(SendAlertJob::class)->sole()->message;
        expect($message->event)->toBe(AlertEvent::CertificateFailed)->and($message->app)->toBe('shop')
            ->and($message->lines[0])->toContain('Fake failure')->and($message->title)->toContain('Shop');
    });

    it('does not repeat the same failure for a day, and starts over after a success', function () {
        $alerts = app(CertificateAlerts::class);
        $alerts->failed($this->shop, 'a');
        Carbon::setTestNow(now()->addHours(6));
        $alerts->failed($this->shop, 'a');
        expect(($this->failures)())->toBe(1);

        Carbon::setTestNow(now()->addHours(19));
        $alerts->failed($this->shop, 'a');
        expect(($this->failures)())->toBe(2);

        $alerts->issued($this->shop);
        $alerts->failed($this->shop, 'b');
        expect(($this->failures)())->toBe(3);
    });

    it('raises certificate.failed for the panel itself, which is no app and cannot be muted', function () {
        $this->shop->update(['alerts_enabled' => false]);
        app(CertificateAlerts::class)->failed(null, 'The CA said no.');

        $message = Queue::pushed(SendAlertJob::class)->sole()->message;
        expect($message->app)->toBeNull()->and($message->title)->toContain('the panel');
    });

    it('raises certificate.expiring for a certificate still not renewed, at most every three days', function () {
        Certificate::query()->create(['app_id' => $this->shop->id, 'status' => CertificateStatus::Active, 'domains' => ['shop.example.com'], 'issued_at' => now()->subDays(77), 'expires_at' => now()->addDays(13), 'last_error' => 'The CA said no.']);

        Artisan::call('commypanel:renew-certificates');
        Artisan::call('commypanel:renew-certificates');
        expect(($this->sent)())->toBe(['certificate.expiring']);
        $message = Queue::pushed(SendAlertJob::class)->first()->message;
        expect($message->title)->toContain('expires in 13 days')->and($message->lines[1])->toContain('The CA said no.');

        Carbon::setTestNow(now()->addDays(2));
        Artisan::call('commypanel:renew-certificates');
        expect(($this->sent)())->toHaveCount(1);

        Carbon::setTestNow(now()->addDays(2));
        Artisan::call('commypanel:renew-certificates');
        expect(($this->sent)())->toHaveCount(2);
    });

    it('is quiet about a certificate with time left or one nobody uses', function () {
        Certificate::query()->create(['app_id' => $this->shop->id, 'status' => CertificateStatus::Active, 'domains' => ['shop.example.com'], 'issued_at' => now()->subDays(10), 'expires_at' => now()->addDays(40)]);
        app(CertificateAlerts::class)->checkExpiring();
        expect(($this->sent)())->toBe([]);

        Certificate::query()->update(['expires_at' => now()->addDays(3)]);
        $this->domain->delete();
        app(CertificateAlerts::class)->checkExpiring();
        expect(($this->sent)())->toBe([]);
    });
});

describe('backups', function () {
    beforeEach(function () {
        $this->dir = storage_path('framework/testing/alert-backups-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/not-a-folder', 'x');
        // a file where the backup folder should be: nothing can be created below it
        config(['commypanel.data_path' => $this->dir.'/not-a-folder']);
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('raises backup.failed when the nightly panel backup fails', function () {
        expect(Artisan::call('commypanel:backup'))->toBe(1);

        $message = Queue::pushed(SendAlertJob::class)->sole()->message;
        expect($message->event)->toBe(AlertEvent::BackupFailed)->and($message->title)->toContain('the panel database')
            ->and($message->app)->toBeNull();
    });

    it('raises backup.failed for a database dump that fails, naming it', function () {
        config(['commypanel.data_path' => $this->dir]);
        Queue::fake([SendAlertJob::class]);
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
        app(ManageDatabases::class)->create($server, 'shop');
        $this->host->failOn('dumpDatabase');
        expect(Artisan::call('commypanel:backup-databases'))->toBe(1);

        expect(Queue::pushed(SendAlertJob::class)->sole()->message->title)->toBe('Backup failed: database shop on Main');
    });

    it('raises backup.failed when a volume archive fails', function () {
        config(['commypanel.data_path' => $this->dir]);
        $this->host->failOn('backupVolume');

        (new BackupVolumeJob($this->shop->id, 'uploads'))->handle(app(VolumeBackups::class), app(AuditLogger::class));

        expect(Queue::pushed(SendAlertJob::class)->sole()->message->title)->toBe('Backup failed: volume uploads of Shop');
    });

    it('raises backup.failed when the off-server copy gave up', function () {
        (new UploadBackupJob('panel', '/x'))->failed(new RemoteBackupFailed('The storage answered 403.'));

        $message = Queue::pushed(SendAlertJob::class)->sole()->message;
        expect($message->title)->toContain('off-server')->and($message->lines)->toBe(['The storage answered 403.']);
    });

    it('cuts a long reason and sends no data of its own', function () {
        BackupAlerts::failed('something', str_repeat('x', 1000));

        expect(Queue::pushed(SendAlertJob::class)->sole()->message->lines[0])->toHaveLength(300);
    });
});
