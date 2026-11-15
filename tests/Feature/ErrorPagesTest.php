<?php

use App\Exceptions\ErrorPages;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_t/401', fn () => abort(401));
        Route::get('/_t/403', fn () => abort(403));
        Route::get('/_t/405-only-post', fn () => 'ok')->name('t.only-get');
        Route::get('/_t/503', fn () => abort(503));
        Route::get('/_t/boom', fn () => throw new RuntimeException('boom'));
        Route::get('/_t/expired', fn () => throw new TokenMismatchException);
        Route::get('/_t/slow', fn () => 'ok')->middleware('throttle:1,1');
    });
    config(['app.debug' => false]);
});

it('shows a page of the panel for the usual errors, with the right status', function (string $url, int $status) {
    $this->get($url)
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', $status));
})->with([
    'missing page' => ['/does/not/exist', 404],
    'sign in needed' => ['/_t/401', 401],
    'forbidden' => ['/_t/403', 403],
    'being updated' => ['/_t/503', 503],
    'crash' => ['/_t/boom', 500],
]);

it('keeps 405 for an address that exists under another method, with the allowed ones', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/dashboard')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 405));
});

it('shows the same page for a signed-in user', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/does/not/exist')->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404)->where('auth.user.email', fn ($v) => is_string($v)));
});

it('answers an Inertia visit with a page, not an overlay', function () {
    $version = app(HandleInertiaRequests::class)->version(request());
    $response = $this->get('/does/not/exist', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest']);

    $response->assertNotFound()->assertHeader('X-Inertia', 'true');
    expect($response->json('component'))->toBe('error')->and($response->json('props.status'))->toBe(404);
});

it('keeps the plain answer for JSON and for the API', function () {
    $this->getJson('/does/not/exist')->assertNotFound()->assertJsonMissingPath('component');
    $this->getJson('/api/v1/nothing')->assertNotFound();
});

it('shows a crash\'s details to a developer instead of the friendly page', function () {
    config(['app.debug' => true]);

    $response = $this->get('/_t/boom');

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('"component":"error"');
});

it('sends someone whose form expired back with a note', function () {
    $this->from('/dashboard')->get('/_t/expired')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning', 'The page expired. Please try again.');
});

it('tells a throttled person how long to wait, as a notice anywhere else', function () {
    $this->from('/dashboard')->get('/_t/slow')->assertOk();

    $this->from('/dashboard')->get('/_t/slow')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('warning', fn ($m) => str_starts_with($m, 'Too many attempts. Try again in '));
});

it('rounds the wait up to whole minutes from a minute on', function (int $seconds, string $expected) {
    expect(ErrorPages::wait($seconds))->toBe($expected);
})->with([[1, '1 second'], [30, '30 seconds'], [60, '1 minute'], [61, '2 minutes'], [300, '5 minutes']]);

it('keeps a bare 429 for requests that are not people in a browser', function () {
    $this->getJson('/_t/slow')->assertOk();
    $this->getJson('/_t/slow')->assertStatus(429);
});
