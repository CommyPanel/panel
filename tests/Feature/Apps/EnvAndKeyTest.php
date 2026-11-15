<?php

use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->shop = App::factory()->create(['slug' => 'shop']);
});

it('sets, updates and removes environment variables', function () {
    $this->post('/apps/shop/env', ['key' => 'DB_HOST', 'value' => 'db'])->assertSessionHasNoErrors();
    $this->post('/apps/shop/env', ['key' => 'DB_HOST', 'value' => 'db2'])->assertSessionHasNoErrors();
    $this->post('/apps/shop/env', ['key' => 'EMPTY', 'value' => ''])->assertSessionHasNoErrors();

    expect($this->shop->fresh()->environment())->toBe(['DB_HOST' => 'db2', 'EMPTY' => ''])
        ->and(DB::table('app_env_vars')->where('key', 'DB_HOST')->value('value'))->not->toContain('db2');

    $this->delete('/apps/shop/env/DB_HOST')->assertSessionHasNoErrors();

    expect($this->shop->fresh()->environment())->toBe(['EMPTY' => '']);
});

it('stops an app from carrying too many variables or too much text', function () {
    foreach (range(1, 200) as $i) {
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'K'.$i, 'value' => 'v']);
    }

    // The 201st is refused; replacing one that exists is not.
    $this->post('/apps/shop/env', ['key' => 'ONE_TOO_MANY', 'value' => 'x'])->assertSessionHasErrors('key');
    $this->post('/apps/shop/env', ['key' => 'K1', 'value' => 'new'])->assertSessionHasNoErrors();
    $this->post('/apps/shop/env/import', ['env' => 'FRESH=1
'])->assertSessionHasErrors('env');
    expect($this->shop->envVars()->count())->toBe(200);
});

it('stops the values of an app from adding up to more than the limit', function () {
    foreach (range(1, 7) as $i) {
        $this->post('/apps/shop/env', ['key' => 'BIG'.$i, 'value' => str_repeat('x', 32768)])->assertSessionHasNoErrors();
    }

    // 8 x 32 KB is exactly 256 KB; the ninth does not fit.
    $this->post('/apps/shop/env', ['key' => 'BIG8', 'value' => str_repeat('x', 32768)])->assertSessionHasNoErrors();
    $this->post('/apps/shop/env', ['key' => 'BIG9', 'value' => 'x'])->assertSessionHasErrors('key');
});

it('rejects invalid or reserved variable names', function (string $key) {
    $this->post('/apps/shop/env', ['key' => $key, 'value' => 'x'])->assertSessionHasErrors('key');

    expect(AppEnvVar::query()->count())->toBe(0);
})->with(['PORT', '1ABC', 'A=B', 'A B', 'A-B', '']);

it('records the variable name but never its value in the audit log', function () {
    $this->post('/apps/shop/env', ['key' => 'SECRET_TOKEN', 'value' => 'supersecretvalue']);

    $log = AuditLog::query()->where('action', 'app.env_set')->firstOrFail();

    expect($log->metadata)->toBe(['app' => 'shop', 'key' => 'SECRET_TOKEN'])
        ->and(json_encode($log->getAttributes()))->not->toContain('supersecretvalue');
});

it('never sends values to the browser', function () {
    AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'A', 'value' => 'topsecretvalue']);

    expect($this->get('/apps/shop')->getContent())->not->toContain('topsecretvalue');
});

describe('deploy keys', function () {
    beforeEach(function () {
        if (Process::run(['ssh-keygen', '-?'])->exitCode() === 127) {
            $this->markTestSkipped('ssh-keygen is not installed.');
        }
    });

    it('generates a key pair once and only exposes the public half', function () {
        $this->post('/apps/shop/deploy-key')->assertSessionHasNoErrors();

        $app = $this->shop->fresh();
        expect($app->deploy_key_public)->toStartWith('ssh-ed25519 ')->toEndWith('commypanel-shop')
            ->and($app->deploy_key_private)->toContain('BEGIN OPENSSH PRIVATE KEY')
            ->and(DB::table('apps')->value('deploy_key_private'))->not->toContain('OPENSSH');

        $page = $this->get('/apps/shop')->getContent();
        expect($page)->toContain('ssh-ed25519')->not->toContain('PRIVATE KEY');
        expect(glob(config('commypanel.data_path').'/tmp/genkey-*'))->toBe([]);
    });
});
