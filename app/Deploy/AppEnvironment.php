<?php

namespace App\Deploy;

use App\Enums\Stack;
use App\Models\App;
use App\Models\AppEnvVar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only code that writes an app's environment variables. For most apps a variable is a row. A
 * Laravel app keeps its variables as one .env text (`apps.env_text`: comments, order and all) and the
 * rows are derived from it on every change, so what a container gets is always what the text says.
 * Writing one variable to such an app edits its line in the text and leaves the rest alone.
 */
class AppEnvironment
{
    /** Whether the app's variables are edited as a .env text. */
    public static function usesText(App $app): bool
    {
        // A draft knows its type only as the hint (the stack is set when it is first built).
        return $app->stack === Stack::Laravel || $app->stack_hint === Stack::Laravel;
    }

    /**
     * The .env text of a Laravel app; built from its variables if it was never saved as text.
     */
    public function text(App $app): string
    {
        return $app->env_text ?? EnvText::render($this->current($app));
    }

    /**
     * Sets one variable.
     *
     * @param  string  $field  the form field limit problems belong to
     * @return bool whether the variable is new
     *
     * @throws ValidationException
     */
    public function set(App $app, string $key, string $value, string $field = 'key'): bool
    {
        EnvLimits::assertFits($app, [$key => $value], $field);

        if (! self::usesText($app)) {
            $created = ! $app->envVars()->where('key', $key)->exists();
            AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);

            return $created;
        }

        $created = ! $app->envVars()->where('key', $key)->exists();
        $this->replaceText($app, EnvText::set($this->text($app), $key, $value), $field);

        return $created;
    }

    /**
     * Sets several variables (an import).
     *
     * @param  array<string, string>  $vars
     *
     * @throws ValidationException
     */
    public function setMany(App $app, array $vars, string $field = 'env'): void
    {
        EnvLimits::assertFits($app, $vars, $field);

        if (! self::usesText($app)) {
            foreach ($vars as $key => $value) {
                AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);
            }

            return;
        }

        $text = $this->text($app);

        foreach ($vars as $key => $value) {
            $text = EnvText::set($text, $key, $value);
        }

        $this->replaceText($app, $text, $field);
    }

    /**
     * Removes one variable.
     *
     * @return bool whether it existed
     */
    public function unset(App $app, string $key): bool
    {
        if (! $app->envVars()->where('key', $key)->exists()) {
            return false;
        }

        if (! self::usesText($app)) {
            $app->envVars()->where('key', $key)->delete();
            $this->touch($app);

            return true;
        }

        $this->replaceText($app, EnvText::remove($this->text($app), $key));

        return true;
    }

    /**
     * Removes every variable.
     *
     * @return list<string> the names that were removed
     */
    public function clear(App $app): array
    {
        $keys = array_values(array_map('strval', $app->envVars()->pluck('key')->all()));

        if (self::usesText($app)) {
            $this->replaceText($app, '');
        } else {
            $app->envVars()->delete();

            if ($keys !== []) {
                $this->touch($app);
            }
        }

        return $keys;
    }

    /**
     * Replaces the whole text and brings the variables in line with it. Nothing is saved when a line is
     * wrong or the app would carry too much; the error names the lines.
     *
     * @return array{added: list<string>, changed: list<string>, removed: list<string>}
     *
     * @throws ValidationException
     */
    public function replaceText(App $app, string $text, string $field = 'text'): array
    {
        $text = EnvText::normalize($text);
        $parsed = EnvText::parse($text);

        if ($parsed['errors'] !== []) {
            throw ValidationException::withMessages([$field => implode("\n", array_map(
                fn (array $e) => __('Line :line: :message', ['line' => $e['line'], 'message' => $e['message']]),
                array_slice($parsed['errors'], 0, 20),
            ))]);
        }

        EnvLimits::assertTotal($parsed['vars'], $field);

        $current = $this->current($app);
        $added = array_values(array_diff(array_keys($parsed['vars']), array_keys($current)));
        $removed = array_values(array_diff(array_keys($current), array_keys($parsed['vars'])));
        $changed = array_values(array_filter(
            array_keys(array_intersect_key($parsed['vars'], $current)),
            fn (string $key) => $parsed['vars'][$key] !== $current[$key],
        ));

        DB::transaction(function () use ($app, $parsed, $added, $changed, $removed, $text): void {
            foreach ([...$added, ...$changed] as $key) {
                AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $parsed['vars'][$key]]);
            }

            foreach ($removed as $key) {
                $app->envVars()->where('key', $key)->delete();
            }

            $app->forceFill(['env_text' => $text])->save();

            if ($removed !== []) {
                $this->touch($app);
            }
        });

        $app->unsetRelation('envVars');

        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * The variables as they are stored now (not the copy a loaded app may carry).
     *
     * @return array<string, string>
     */
    private function current(App $app): array
    {
        return $app->envVars()->get()->pluck('value', 'key')->all();
    }

    /** The running container was made without this change: the app has changes to apply. */
    private function touch(App $app): void
    {
        App::query()->whereKey($app->id)->update(['config_changed_at' => now()]);
        $app->unsetRelation('envVars');
        $app->setAttribute('config_changed_at', now());
        $app->syncOriginalAttribute('config_changed_at');
    }
}
