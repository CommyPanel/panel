<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the login page', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('logs in and out', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);

    $this->post('/logout');
    $this->assertGuest();
});

it('rejects a wrong password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('throttles repeated failed logins', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    // A message on the form (and no bare 429 page for the browser to show in an overlay).
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect()
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toStartWith('Too many attempts. Try again in ');
    $this->assertGuest();
});

it('redirects guests to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/settings/security')->assertRedirect('/login');
});

it('has no public registration or email password reset', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['GET', '/forgot-password'],
    ['POST', '/forgot-password'],
    ['POST', '/reset-password'],
]);

it('renders the dashboard for a logged in user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
});

it('asks for the password before showing security settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/security')->assertRedirect('/user/confirm-password');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('auth.twoFactor.enabled', false)
            ->where('auth.twoFactor.pending', false));
});
