<?php

use Composer\Semver\Semver;

/**
 * Ubuntu 24.04 ships PHP 8.3 and is a supported platform. The lock is resolved on
 * a developer machine that usually has a newer PHP, so a package that needs 8.4 or
 * later (Symfony 8, for one) can slip in and the installer then fails on 8.3.
 */
it('locks only production packages that run on the oldest supported PHP', function () {
    $lock = json_decode((string) file_get_contents(__DIR__.'/../../composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    $tooNew = [];
    foreach ($lock['packages'] as $package) {
        $php = $package['require']['php'] ?? null;
        if ($php !== null && ! Semver::satisfies('8.3.6', $php)) {
            $tooNew[] = $package['name'].' '.$package['version'].' needs PHP '.$php;
        }
    }

    expect($tooNew)->toBe([]);
});
