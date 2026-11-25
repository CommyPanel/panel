<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Deploy\AppEnvironment;
use App\Deploy\EnvText;
use App\Deploy\LaravelEnvTemplate;
use App\Enums\DeploymentStatus;
use App\Enums\SetupStep;
use App\Enums\Stack;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Laravel app's variables as one .env text: shown only after a password, saved as a whole, and the
 * variables the containers get are derived from it. Other apps keep their list.
 */
const SECRET_LINE = 'STRIPE_SECRET=sk_live_topsecret';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->blog = App::factory()->create(['slug' => 'blog', 'name' => 'Blog', 'stack' => Stack::Laravel]);
    $this->plain = App::factory()->create(['slug' => 'plain', 'stack' => Stack::Go]);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
    $this->text = "# Blog\nAPP_NAME=Blog\nAPP_ENV=production\n\n# payments\n".SECRET_LINE."\n";

    $this->save = fn (string $text) => ($this->confirmed)()->put('/apps/blog/env/text', ['text' => $text]);
});

describe('reading the text', function () {
    it('needs a recent password, says nothing of the text in the page, and is audited without values', function () {
        // Seeded directly: a request made with a confirmed password would keep the confirmation for the rest of the test.
        app(AppEnvironment::class)->replaceText($this->blog, $this->text);

        $this->getJson('/apps/blog/env/text')->assertStatus(423);
        expect(AuditLog::query()->where('action', 'app.env_revealed')->count())->toBe(0);

        $response = ($this->confirmed)()->getJson('/apps/blog/env/text')->assertOk();

        expect($response->json('text'))->toBe($this->text)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(AuditLog::query()->where('action', 'app.env_revealed')->count())->toBe(1);

        $log = AuditLog::query()->where('action', 'app.env_revealed')->firstOrFail();

        expect(json_encode($log->toArray()))->not->toContain('topsecret')
            ->and($log->metadata['count'] ?? null)->toBe(3);

        // The page itself carries the names count and the flag, never the text.
        $page = $this->get('/apps/blog');
        $page->assertInertia(fn (Assert $p) => $p->where('app.env_text', true)->where('env_keys', ['APP_ENV', 'APP_NAME', 'STRIPE_SECRET']));
        expect($page->getContent())->not->toContain('topsecret')->not->toContain('# payments');
    });

    it('builds the text from the variables of an app that never saved one', function () {
        AppEnvVar::query()->create(['app_id' => $this->blog->id, 'key' => 'B', 'value' => 'two words']);
        AppEnvVar::query()->create(['app_id' => $this->blog->id, 'key' => 'A', 'value' => '1']);

        expect(($this->confirmed)()->getJson('/apps/blog/env/text')->json('text'))->toBe("A=1\nB='two words'\n");
    });

    it('is only for Laravel apps', function () {
        ($this->confirmed)()->getJson('/apps/plain/env/text')->assertNotFound();
        ($this->confirmed)()->put('/apps/plain/env/text', ['text' => 'A=1'])->assertNotFound();

        expect(AppEnvVar::query()->where('app_id', $this->plain->id)->count())->toBe(0);
    });

    it('needs the permission to manage variables', function () {
        $this->actingAs(User::factory()->regular()->create());

        ($this->confirmed)()->getJson('/apps/blog/env/text')->assertForbidden();
        ($this->confirmed)()->put('/apps/blog/env/text', ['text' => 'A=1'])->assertForbidden();
    });
});

describe('saving the text', function () {
    it('keeps the text as written and derives the variables from it', function () {
        ($this->save)($this->text)->assertSessionHasNoErrors();

        $blog = $this->blog->fresh();

        expect($blog->env_text)->toBe($this->text)
            ->and($blog->environment())->toEqual(['APP_NAME' => 'Blog', 'APP_ENV' => 'production', 'STRIPE_SECRET' => 'sk_live_topsecret'])
            ->and(DB::table('apps')->where('id', $blog->id)->value('env_text'))->not->toContain('topsecret');
    });

    it('applies only the difference, and says which names changed in the audit log, never values', function () {
        ($this->save)($this->text);
        AuditLog::query()->delete();

        ($this->save)("# Blog\nAPP_NAME='My Blog'\nAPP_ENV=production\n\nNEW_ONE=hunter2\n")->assertSessionHasNoErrors();

        $blog = $this->blog->fresh();
        $log = AuditLog::query()->where('action', 'app.env_changed')->sole();

        expect($blog->environment())->toEqual(['APP_NAME' => 'My Blog', 'APP_ENV' => 'production', 'NEW_ONE' => 'hunter2'])
            ->and($log->metadata['added'])->toBe(['NEW_ONE'])
            ->and($log->metadata['changed'])->toBe(['APP_NAME'])
            ->and($log->metadata['removed'])->toBe(['STRIPE_SECRET'])
            ->and(json_encode($log->toArray()))->not->toContain('hunter2')->not->toContain('topsecret')->not->toContain('My Blog');
    });

    it('keeps changed comments without calling it a variable change', function () {
        ($this->save)($this->text);
        $blog = $this->blog->fresh();
        $blog->forceFill(['config_changed_at' => null])->save();
        AuditLog::query()->delete();

        ($this->save)(str_replace('# payments', '# billing', $this->text))->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toContain('# billing')
            ->and($this->blog->fresh()->config_changed_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'app.env_changed')->count())->toBe(0);
    });

    it('offers Apply changes once a variable differs from the running container', function () {
        $deployment = Deployment::factory()->create(['app_id' => $this->blog->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001, 'created_at' => now()->subHour()]);
        $this->blog->update(['active_deployment_id' => $deployment->id]);

        ($this->save)($this->text)->assertSessionHasNoErrors();

        $this->get('/apps/blog')->assertInertia(fn (Assert $page) => $page->where('app.pending_changes', true));
    });

    it('refuses the whole text, naming the lines, when a line is wrong, and saves nothing', function () {
        ($this->save)($this->text);

        ($this->save)("APP_NAME=Other\nnot valid\nPORT=80\nAPP_NAME=twice\n")->assertSessionHasErrors('text');

        $error = session('errors')->first('text');

        expect($error)->toContain('Line 2')->toContain('Line 3')->toContain('Line 4')
            ->and($this->blog->fresh()->env_text)->toBe($this->text)
            ->and($this->blog->fresh()->environment()['APP_NAME'])->toBe('Blog');
    });

    it('holds an app to the same limits as the list', function () {
        $many = implode("\n", array_map(fn (int $i) => "K{$i}=v", range(1, 201)));

        ($this->save)($many)->assertSessionHasErrors('text');

        $big = implode("\n", array_map(fn (int $i) => "B{$i}=".str_repeat('x', 32768), range(1, 9)));

        ($this->save)($big)->assertSessionHasErrors('text');
        expect($this->blog->envVars()->count())->toBe(0);

        ($this->save)(implode("\n", array_map(fn (int $i) => "K{$i}=v", range(1, 200))))->assertSessionHasNoErrors();
        expect($this->blog->envVars()->count())->toBe(200);
    });

    it('saves an empty text as no variables', function () {
        ($this->save)($this->text);
        ($this->save)('')->assertSessionHasNoErrors();

        expect($this->blog->fresh()->environment())->toBe([])->and($this->blog->fresh()->env_text)->toBe('');
    });

    it('needs a recent password', function () {
        $this->put('/apps/blog/env/text', ['text' => 'A=1'])->assertRedirect();

        expect($this->blog->fresh()->env_text)->toBeNull();
    });
});

describe('the other ways in edit the text', function () {
    beforeEach(fn () => ($this->save)($this->text));

    it('a single variable from the list form replaces its line, or is added at the end', function () {
        $this->post('/apps/blog/env', ['key' => 'APP_ENV', 'value' => 'staging'])->assertSessionHasNoErrors();
        $this->post('/apps/blog/env', ['key' => 'FRESH', 'value' => 'a b'])->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toBe("# Blog\nAPP_NAME=Blog\nAPP_ENV=staging\n\n# payments\n".SECRET_LINE."\nFRESH='a b'\n")
            ->and($this->blog->fresh()->environment()['APP_ENV'])->toBe('staging');
    });

    it('the pen edit replaces one value in the text', function () {
        ($this->confirmed)()->put('/apps/blog/env/STRIPE_SECRET', ['value' => 'rotated'])->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toContain("STRIPE_SECRET=rotated\n")->toContain('# payments');
    });

    it('removing one removes its line and keeps the rest', function () {
        $this->delete('/apps/blog/env/APP_ENV')->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toBe("# Blog\nAPP_NAME=Blog\n\n# payments\n".SECRET_LINE."\n")
            ->and($this->blog->fresh()->environment())->not->toHaveKey('APP_ENV');
    });

    it('an import adds its lines to the text', function () {
        $this->post('/apps/blog/env/import', ['env' => "APP_NAME=Imported\nA=1\n"])->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toBe("# Blog\nAPP_NAME=Imported\nAPP_ENV=production\n\n# payments\n".SECRET_LINE."\nA=1\n");
    });

    it('delete all empties the text', function () {
        $this->delete('/apps/blog/env')->assertSessionHasNoErrors();

        expect($this->blog->fresh()->env_text)->toBe('')->and($this->blog->fresh()->envVars()->count())->toBe(0);
    });

    it('the API sets and removes through the text and still never returns a value', function () {
        app(SyncRolesAndPermissions::class)();
        $token = app(TokenAccess::class)->create(User::factory()->create(), 'ci', [TokenAccess::ENV], [], 30)->plainTextToken;
        $api = fn () => $this->withToken($token)->withHeaders(['Accept' => 'application/json']);

        app('auth')->forgetGuards();
        $created = $api()->putJson('/api/v1/apps/blog/env/NEW_KEY', ['value' => 'hunter2'])->assertCreated();
        app('auth')->forgetGuards();
        $api()->putJson('/api/v1/apps/blog/env/APP_ENV', ['value' => 'local'])->assertOk();
        app('auth')->forgetGuards();
        $api()->deleteJson('/api/v1/apps/blog/env/STRIPE_SECRET')->assertOk();
        app('auth')->forgetGuards();
        $api()->deleteJson('/api/v1/apps/blog/env/GHOST')->assertNotFound();

        expect($created->getContent())->not->toContain('hunter2')
            ->and($this->blog->fresh()->env_text)->toBe("# Blog\nAPP_NAME=Blog\nAPP_ENV=local\n\n# payments\nNEW_KEY=hunter2\n");
    });

    it('does not touch the list of any other app', function () {
        $this->post('/apps/plain/env', ['key' => 'A', 'value' => '1'])->assertSessionHasNoErrors();

        expect($this->plain->fresh()->env_text)->toBeNull()->and($this->plain->fresh()->environment())->toBe(['A' => '1']);

        $this->delete('/apps/plain/env/A')->assertSessionHasNoErrors();
        $this->post('/apps/plain/env/import', ['env' => "X=1\nY=2"]);
        $this->delete('/apps/plain/env')->assertSessionHasNoErrors();

        expect($this->plain->fresh()->envVars()->count())->toBe(0);
    });

    it('downloads the text as it was saved, comments included', function () {
        $response = ($this->confirmed)()->get('/apps/blog/env/download')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=".env.blog"');

        expect($response->getContent())->toBe($this->text)
            ->and(AuditLog::query()->where('action', 'app.env_downloaded')->count())->toBe(1);
    });
});

describe('the starting text', function () {
    it('has a key that is 32 random bytes, different every time, and reads without a mistake', function () {
        $template = new LaravelEnvTemplate;

        $keys = collect(range(1, 3))->map(function () use ($template) {
            $parsed = EnvText::parse($template->generate($this->blog));

            expect($parsed['errors'])->toBe([]);

            return $parsed['vars']['APP_KEY'];
        });

        expect($keys->unique())->toHaveCount(3);

        foreach ($keys as $key) {
            expect($key)->toStartWith('base64:')->and(strlen((string) base64_decode(substr($key, 7), true)))->toBe(32);
        }
    });

    it('sets production values, the domain, and queue and Redis choices', function () {
        $template = new LaravelEnvTemplate;

        $plain = EnvText::parse($template->generate($this->blog))['vars'];
        $queue = EnvText::parse($template->generate($this->blog, 'blog.example.com', queue: true))['vars'];
        $redis = EnvText::parse($template->generate($this->blog, '*.example.com', queue: true, redis: true))['vars'];

        expect($plain)->toMatchArray(['APP_NAME' => 'Blog', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost', 'LOG_CHANNEL' => 'stderr', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file'])
            ->and($plain)->not->toHaveKey('DB_HOST')->not->toHaveKey('PORT')
            ->and($queue['APP_URL'])->toBe('https://blog.example.com')
            ->and($queue['QUEUE_CONNECTION'])->toBe('database')
            ->and($redis['APP_URL'])->toBe('https://example.com')
            ->and([$redis['QUEUE_CONNECTION'], $redis['CACHE_STORE'], $redis['SESSION_DRIVER']])->toBe(['redis', 'redis', 'redis']);
    });

    it('quotes an app name that needs it', function () {
        $app = App::factory()->create(['slug' => 'quirky', 'name' => "Bob's \$hop", 'stack' => Stack::Laravel]);

        expect(EnvText::parse((new LaravelEnvTemplate)->generate($app))['vars']['APP_NAME'])->toBe("Bob's \$hop");
    });

    it('is stored as the text, and the variables follow', function () {
        $diff = (new LaravelEnvTemplate)->apply($this->blog, (new LaravelEnvTemplate)->generate($this->blog, 'blog.example.com'));

        $blog = $this->blog->fresh();

        expect($diff['added'])->toContain('APP_KEY', 'APP_URL')
            ->and($blog->environment())->toHaveKey('APP_KEY')
            ->and($blog->env_text)->toContain('# Logs go to')
            ->and(app(AppEnvironment::class)->text($blog))->toBe($blog->env_text);
    });
});

it('serves the text of a Laravel app that is still being set up, whose type is only a hint', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $app = App::factory()->create(['slug' => 'draft', 'stack' => null, 'stack_hint' => Stack::Laravel, 'setup_step' => SetupStep::Review, 'env_text' => "APP_ENV=production\n"]);

    $this->withSession(['auth.password_confirmed_at' => time()])->getJson("/apps/{$app->slug}/env/text")
        ->assertOk()->assertJson(['text' => "APP_ENV=production\n"]);
});
