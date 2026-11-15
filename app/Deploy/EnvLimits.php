<?php

namespace App\Deploy;

use App\Models\App;
use Illuminate\Validation\ValidationException;

/**
 * How much environment one app may carry. Every variable goes into the container when it is made,
 * and the system refuses to start a process whose environment is too large, so an unbounded list
 * (by a person pasting, or a token in a loop) could make an app that no longer starts. One rule for
 * the panel's forms and for the API.
 */
final class EnvLimits
{
    public const MAX_VARIABLES = EnvParser::MAX_VARS;

    /** All values together, in bytes. */
    public const MAX_BYTES = 262144;

    /**
     * Checks that the app still fits after these variables are set (new ones add, existing ones are replaced).
     *
     * @param  array<string, string>  $changes  key => value
     * @param  string  $field  the form field the message belongs to
     *
     * @throws ValidationException
     */
    public static function assertFits(App $app, array $changes, string $field): void
    {
        $sizes = [];

        if ($app->exists) {
            foreach ($app->envVars()->get() as $variable) {
                $sizes[$variable->key] = strlen((string) $variable->value);
            }
        }

        foreach ($changes as $key => $value) {
            $sizes[$key] = strlen($value);
        }

        if (count($sizes) > self::MAX_VARIABLES) {
            throw ValidationException::withMessages([$field => __('An app can have at most :max variables.', ['max' => self::MAX_VARIABLES])]);
        }

        if (array_sum($sizes) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => __('The values of an app\'s variables can add up to :size KB at most.', ['size' => intdiv(self::MAX_BYTES, 1024)])]);
        }
    }
}
