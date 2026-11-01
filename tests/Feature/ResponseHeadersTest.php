<?php

use App\Models\App;
use App\Models\User;

/**
 * A full page load that nginx cannot read back is a 502. nginx reads the
 * response headers from PHP into one buffer (4k by default), and the app's
 * detail page, which loads the most script chunks, once crossed it because of
 * a Link preload header listing every chunk. In-app navigation sends JSON with
 * no such header, which is why only a reload failed.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    App::factory()->create(['slug' => 'shop']);
});

it('sends no Link preload headers', function (string $path) {
    $this->get($path)->assertOk()->assertHeaderMissing('Link');
})->with(['/dashboard', '/apps', '/apps/shop', '/server']);

it('keeps the headers of a full page load well under nginx\'s default buffer', function (string $path) {
    $response = $this->get($path)->assertOk();

    $bytes = strlen((string) $response->baseResponse->headers);

    // Half of the 4096 bytes nginx allows by default, so growth has room.
    expect($bytes)->toBeLessThan(2048, "{$path} sends {$bytes} bytes of headers");
})->with(['/dashboard', '/apps/shop', '/server']);
