<?php

use App\Audit\Severity;
use App\Backups\Remote\RemoteSettings;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
    app(RemoteSettings::class)->save([
        'enabled' => true, 'endpoint' => 'https://s3.example.com', 'region' => 'eu-central-1', 'bucket' => 'my-backups',
        'prefix' => 'commypanel/main', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'super-secret-value', 'keep' => 5,
    ]);
});

function s3Page(array $objects, ?string $next = null): string
{
    $items = '';
    foreach ($objects as $key => [$size, $time]) {
        $items .= "<Contents><Key>{$key}</Key><LastModified>".gmdate('Y-m-d\TH:i:s.000\Z', $time)."</LastModified><Size>{$size}</Size></Contents>";
    }

    return '<?xml version="1.0"?><ListBucketResult><IsTruncated>'.($next ? 'true' : 'false').'</IsTruncated>'
        .($next ? "<NextContinuationToken>{$next}</NextContinuationToken>" : '').$items.'</ListBucketResult>';
}

it('lists the copies grouped by kind, newest first, across pages, and ignores other objects', function () {
    Http::fake(function (HttpRequest $r) {
        return str_contains($r->url(), 'continuation-token=tok1')
            ? Http::response(s3Page([
                'commypanel/main/volumes/shop/uploads-20261105-010101.tar.gz' => [300, 1_700_000_300],
                'commypanel/main/notes.txt' => [1, 1_700_000_400],
                'commypanel/main/databases/main/../x.sql.gz' => [1, 1_700_000_500],
            ]))
            : Http::response(s3Page([
                'commypanel/main/panel/panel-20261105-030000.sqlite.gz' => [100, 1_700_000_100],
                'commypanel/main/panel/panel-20261106-030000.sqlite.gz' => [110, 1_700_000_200],
                'commypanel/main/databases/main/shop-20261105-040000.sql.gz' => [200, 1_700_000_150],
            ], 'tok1'));
    });

    $json = $this->getJson('/settings/backups/remote/files')->assertOk();

    expect(array_column($json->json('groups'), 'kind'))->toBe(['panel', 'database', 'volume'])
        ->and($json->json('groups.0.files.0.name'))->toBe('panel-20261106-030000.sqlite.gz')
        ->and($json->json('groups.1.label'))->toBe('main')
        ->and($json->json('groups.2.files.0.size'))->toBe(300)
        ->and($json->json('truncated'))->toBeFalse()
        ->and($json->getContent())->not->toContain('super-secret-value')->not->toContain('notes.txt');

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), 'prefix=commypanel%2Fmain%2F') && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256'));
});

it('says what went wrong without secrets', function () {
    Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code><Message>no</Message></Error>', 403)]);

    $this->getJson('/settings/backups/remote/files')->assertStatus(502)->assertJsonPath('error', fn ($m) => str_contains($m, 'may not do this') && ! str_contains($m, 'super-secret-value'));
});

it('does not follow a redirect', function () {
    Http::fake(['*' => Http::response('', 307, ['Location' => 'https://evil.example.com/'])]);

    $this->getJson('/settings/backups/remote/files')->assertStatus(502);
    Http::assertSentCount(1);
});

it('is refused while the copy is off, and without the permission', function () {
    app(RemoteSettings::class)->save([
        'enabled' => false, 'endpoint' => 'https://s3.example.com', 'region' => '', 'bucket' => 'b', 'prefix' => '', 'access_key' => 'k', 'secret_key' => '', 'keep' => 5,
    ]);
    Http::fake();

    $this->getJson('/settings/backups/remote/files')->assertStatus(502);
    Http::assertNothingSent();

    $this->actingAs(User::factory()->create(['is_super' => false]));
    $this->getJson('/settings/backups/remote/files')->assertForbidden();
});

describe('downloading', function () {
    it('needs the password, streams the file and records it', function () {
        Http::fake(['https://s3.example.com/*' => Http::response('gzip bytes', 200)]);
        $path = 'databases/main/shop-20261105-040000.sql.gz';

        $this->get('/settings/backups/remote/download?path='.$path)->assertRedirect();
        Http::assertNothingSent();

        $response = ($this->confirmed)()->get('/settings/backups/remote/download?path='.$path)->assertOk();

        expect($response->streamedContent())->toBe('gzip bytes')
            ->and($response->headers->get('Content-Disposition'))->toContain('shop-20261105-040000.sql.gz')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(AuditLog::query()->where('action', 'backup.remote_downloaded')->first()->metadata)->toBe(['file' => $path])
            ->and(Severity::of('backup.remote_downloaded'))->toBe('warning');

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://s3.example.com/my-backups/commypanel/main/databases/main/shop-20261105-040000.sql.gz'));
    });

    it('only fetches names that look like backups', function (string $path) {
        Http::fake();

        ($this->confirmed)()->get('/settings/backups/remote/download?path='.rawurlencode($path))->assertSessionHasErrors('remote');
        Http::assertNothingSent();
    })->with(['traversal' => '../other/x.sql.gz', 'another file' => 'notes.txt', 'deep' => 'databases/main/x/shop-20261105-040000.sql.gz', 'dots' => 'databases/../panel/panel-20261105-030000.sqlite.gz']);

    it('needs the manage permission', function () {
        $this->actingAs(User::factory()->create(['is_super' => false]));

        ($this->confirmed)()->get('/settings/backups/remote/download?path=panel/panel-20261105-030000.sqlite.gz')->assertForbidden();
    });
});
