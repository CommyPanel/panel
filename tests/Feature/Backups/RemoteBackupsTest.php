<?php

use App\Backups\DatabaseSnapshotter;
use App\Backups\PanelBackups;
use App\Backups\Remote\RemoteBackupFailed;
use App\Backups\Remote\RemoteBackups;
use App\Backups\Remote\RemoteSettings;
use App\Jobs\UploadBackupJob;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

const S3_FORM = [
    'enabled' => true,
    'endpoint' => 'https://s3.example.com',
    'region' => 'eu-central-1',
    'bucket' => 'my-backups',
    'prefix' => 'commypanel/main',
    'access_key' => 'AKIAEXAMPLE',
    'secret_key' => 'super-secret-value',
    'keep' => 2,
];

beforeEach(function () {
    $this->dir = storage_path('framework/testing/remote-'.bin2hex(random_bytes(4)));
    mkdir($this->dir, 0700, true);
    config(['commypanel.data_path' => $this->dir]);
    $this->actingAs(User::factory()->create());
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
    $this->file = $this->dir.'/shop-20261103-040000.sql.gz';
    file_put_contents($this->file, gzencode('dump'));
});

afterEach(fn () => File::deleteDirectory($this->dir));

function listing(array $objects): string
{
    $items = '';
    foreach ($objects as $key => $time) {
        $items .= '<Contents><Key>'.$key.'</Key><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z', $time).'</LastModified></Contents>';
    }

    return '<?xml version="1.0"?><ListBucketResult><IsTruncated>false</IsTruncated>'.$items.'</ListBucketResult>';
}

describe('the settings', function () {
    it('keeps the secret encrypted and never hands it to a page', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM)->assertSessionHasNoErrors();

        expect(Setting::read('backups.s3'))->not->toContain('super-secret-value')
            ->and(app(RemoteSettings::class)->toArray())->toMatchArray(['enabled' => true, 'bucket' => 'my-backups', 'prefix' => 'commypanel/main', 'has_secret' => true])
            ->and(app(RemoteSettings::class)->toArray())->not->toHaveKey('secret_key');

        $this->get('/settings/backups')->assertInertia(fn (Assert $page) => $page
            ->where('remote.has_secret', true)
            ->where('remote.access_key', 'AKIAEXAMPLE')
            ->missing('remote.secret_key')
            ->missing('remote.secret'));
    });

    it('keeps the saved secret when the field is left empty', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        $before = Setting::read('backups.s3');

        ($this->confirmed)()->put('/settings/backups/remote', [...S3_FORM, 'secret_key' => '', 'keep' => 5])->assertSessionHasNoErrors();

        expect(json_decode((string) Setting::read('backups.s3'), true)['secret'])->toBe(json_decode((string) $before, true)['secret'])
            ->and(app(RemoteSettings::class)->toArray()['keep'])->toBe(5);
    });

    it('needs a secret the first time, and the password', function () {
        $this->put('/settings/backups/remote', S3_FORM)->assertRedirect();
        expect(Setting::read('backups.s3'))->toBeNull();

        ($this->confirmed)()->put('/settings/backups/remote', [...S3_FORM, 'secret_key' => ''])->assertSessionHasErrors('secret_key');
    });

    it('is recorded without the secret', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);

        $entry = AuditLog::query()->where('action', 'backup.remote_changed')->first();

        expect($entry->metadata['bucket'])->toBe('my-backups')
            ->and($entry->metadata['secret_changed'])->toBeTrue()
            ->and(json_encode($entry->metadata))->not->toContain('super-secret-value');
    });

    it('refuses addresses that are not a storage service over https, or point at this server', function (string $endpoint) {
        ($this->confirmed)()->put('/settings/backups/remote', [...S3_FORM, 'endpoint' => $endpoint])->assertSessionHasErrors('endpoint');
    })->with([
        'plain http' => 'http://s3.example.com',
        'a path' => 'https://s3.example.com/bucket',
        'credentials in it' => 'https://user:pass@s3.example.com',
        'localhost' => 'https://localhost',
        'loopback' => 'https://127.0.0.1',
        'the metadata address' => 'https://169.254.169.254',
        'ipv6 loopback' => 'https://[::1]',
        'not an address' => 'ftp://example.com',
    ]);

    it('accepts a storage in a private network', function () {
        ($this->confirmed)()->put('/settings/backups/remote', [...S3_FORM, 'endpoint' => 'https://10.0.0.7:9000'])->assertSessionHasNoErrors();
    });

    it('can be removed', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        ($this->confirmed)()->delete('/settings/backups/remote')->assertSessionHas('status');

        expect(Setting::read('backups.s3'))->toBeNull();
    });

    it('needs the manage permission', function () {
        $this->actingAs(User::factory()->create(['is_super' => false]));

        $this->put('/settings/backups/remote', S3_FORM)->assertForbidden();
        $this->post('/settings/backups/remote/test', S3_FORM)->assertForbidden();
    });
});

describe('the connection test', function () {
    it('writes and deletes a small file, signed for the bucket', function () {
        Http::fake(['https://s3.example.com/*' => Http::response('', 200)]);

        $this->post('/settings/backups/remote/test', S3_FORM)->assertSessionHas('status');

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_starts_with($r->url(), 'https://s3.example.com/my-backups/commypanel/main/.commypanel-test-')
            && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/')
            && str_contains($r->header('Authorization')[0], '/eu-central-1/s3/aws4_request'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    });

    it('says in words what the storage refused', function (string $code, string $words) {
        Http::fake(['*' => Http::response('<Error><Code>'.$code.'</Code><Message>x</Message></Error>', 403)]);

        $this->post('/settings/backups/remote/test', S3_FORM)->assertSessionHasErrors('remote');

        expect(session('errors')->first('remote'))->toContain($words);
    })->with([
        ['InvalidAccessKeyId', 'access key is not known'],
        ['SignatureDoesNotMatch', 'secret key is wrong'],
        ['NoSuchBucket', 'bucket does not exist'],
        ['AccessDenied', 'may not do this'],
    ]);

    it('does not follow a redirect with the credentials', function () {
        Http::fake(['*' => Http::response('', 307, ['Location' => 'https://evil.example/'])]);

        $this->post('/settings/backups/remote/test', S3_FORM)->assertSessionHasErrors('remote');

        expect(session('errors')->first('remote'))->toContain('redirected');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example'));
    });

    it('uses the saved secret when the form leaves it empty', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        Http::fake(['*' => Http::response('', 200)]);

        $this->post('/settings/backups/remote/test', [...S3_FORM, 'secret_key' => ''])->assertSessionHas('status');
    });
});

describe('copying a backup', function () {
    it('queues nothing while the copy is off', function () {
        Queue::fake();

        app(RemoteBackups::class)->queue('databases/main', $this->file);

        Queue::assertNothingPushed();
    });

    it('queues the copy of each new backup once it is on', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        Queue::fake();

        app(RemoteBackups::class)->queue('databases/main', $this->file);

        Queue::assertPushed(UploadBackupJob::class, fn ($j) => $j->series === 'databases/main' && $j->file === $this->file);
    });

    it('is queued by the panel backup itself', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        Queue::fake();
        app()->bind(DatabaseSnapshotter::class, fn () => new class implements DatabaseSnapshotter
        {
            public function snapshot(string $toPath): void
            {
                (new PDO('sqlite:'.$toPath))->exec('CREATE TABLE migrations (id INTEGER); CREATE TABLE users (id INTEGER); CREATE TABLE apps (id INTEGER)');
            }

            public function livePath(): ?string
            {
                return null;
            }
        });

        app(PanelBackups::class)->create();

        Queue::assertPushed(UploadBackupJob::class, fn ($j) => $j->series === 'panel' && str_ends_with($j->file, '.sqlite.gz'));
    });

    it('never turns a made backup into a failure when the settings cannot be read', function () {
        Schema::drop('settings');

        app(RemoteBackups::class)->queue('panel', $this->file);

        expect(true)->toBeTrue();
    });

    it('uploads under the prefix and the series, then keeps only the newest copies of each file', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        $now = time();
        $base = 'commypanel/main/databases/main/';
        $deleted = [];

        Http::fake(function (HttpRequest $r) use (&$deleted, $now, $base) {
            if ($r->method() === 'GET') {
                return Http::response(listing([
                    $base.'shop-20261101-040000.sql.gz' => $now - 300,
                    $base.'shop-20261102-040000.sql.gz' => $now - 200,
                    $base.'shop-20261103-040000.sql.gz' => $now - 100,
                    $base.'blog-20261101-040000.sql.gz' => $now - 300,
                    $base.'notes.txt' => $now - 900,
                ]));
            }
            if ($r->method() === 'DELETE') {
                $deleted[] = $r->url();
            }

            return Http::response('', 200);
        });

        app(RemoteBackups::class)->upload('databases/main', $this->file);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://s3.example.com/my-backups/'.$base.'shop-20261103-040000.sql.gz');

        // Two are kept of shop; blog has one; a file that is not ours is never touched.
        expect($deleted)->toBe(['https://s3.example.com/my-backups/'.$base.'shop-20261101-040000.sql.gz'])
            ->and(app(RemoteSettings::class)->toArray()['last_ok_at'])->not->toBeNull()
            ->and(app(RemoteSettings::class)->toArray()['last_error'])->toBeNull();
    });

    it('remembers a failure to show it, and tries again', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        Http::fake(['*' => Http::response('<Error><Code>NoSuchBucket</Code></Error>', 404)]);

        expect(fn () => app(RemoteBackups::class)->upload('databases/main', $this->file))->toThrow(RemoteBackupFailed::class, 'bucket does not exist');

        expect(app(RemoteSettings::class)->toArray()['last_error'])->toContain('bucket does not exist');

        (new UploadBackupJob('databases/main', $this->file))->failed(new RemoteBackupFailed('The upload was refused: the bucket does not exist.'));
        expect(app(RemoteSettings::class)->toArray()['last_error'])->toContain('refused');
    });

    it('skips a file that was deleted since it was queued', function () {
        ($this->confirmed)()->put('/settings/backups/remote', S3_FORM);
        Http::fake();
        unlink($this->file);

        app(RemoteBackups::class)->upload('databases/main', $this->file);

        Http::assertNothingSent();
    });
});
