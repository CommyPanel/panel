<?php

use App\Models\User;

it('tells crawlers to ignore every page, signed in or not', function (string $url) {
    $this->get($url)->assertSee('<meta name="robots" content="noindex, nofollow">', false);
})->with(['/login', '/does/not/exist']);

it('tells crawlers to ignore the pages behind the sign-in too', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});
