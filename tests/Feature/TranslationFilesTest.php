<?php

use App\I18n\Locales;

it('has a sound language file for every language the panel offers', function () {
    foreach (array_keys(Locales::available()) as $code) {
        if ($code === 'en') {
            continue;
        }

        $path = lang_path("{$code}.json");
        expect(is_file($path))->toBeTrue("lang/{$code}.json is missing");

        $data = json_decode((string) file_get_contents($path), true);
        expect($data)->toBeArray("lang/{$code}.json is not valid JSON");

        foreach ($data as $key => $value) {
            expect($value)->toBeString()->not->toBe('', "empty translation for [{$key}] in {$code}");
        }
    }
});

it('passes the translation checker (placeholders, tags, plural forms, unknown files)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    $output = [];
    exec(escapeshellarg($node).' '.escapeshellarg(base_path('scripts/i18n.mjs')).' check 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", array_filter($output, fn ($l) => str_starts_with($l, 'error'))));
});

it("has Laravel's own messages (validation, sign-in, passwords, pagination) for every language", function () {
    foreach (array_keys(Locales::available()) as $code) {
        if ($code === 'en') {
            continue;
        }

        foreach (['validation', 'auth', 'passwords', 'pagination'] as $file) {
            expect(is_file(lang_path("{$code}/{$file}.php")))->toBeTrue("lang/{$code}/{$file}.php is missing");
        }

        app()->setLocale($code);

        try {
            expect(__('validation.required', ['attribute' => 'x']))->not->toBe('validation.required')
                ->and(__('validation.required', ['attribute' => 'x']))->not->toBe('The x field is required.')
                ->and(__('auth.failed'))->not->toBe('These credentials do not match our records.');
        } finally {
            app()->setLocale('en');
        }
    }
});
