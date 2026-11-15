<?php

/**
 * The release tarball is built from a fixed list of folders (scripts/build-release.sh). A folder the
 * panel reads at runtime that is not on the list is simply missing on a real server, and nothing in the
 * test suite would notice: translations once were. This keeps the list and the code honest.
 */
it('ships every folder the panel reads at runtime', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/build-release.sh');

    preg_match('/for path in ([^;]+); do/', $script, $m);
    $shipped = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];

    foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources/views', 'routes', 'installer'] as $needed) {
        expect($shipped)->toContain($needed);
    }
});

it('refuses to build a release without the translations', function () {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/build-release.sh');

    expect($script)->toContain('lang/en.json')->toContain('is missing from the release');
});
