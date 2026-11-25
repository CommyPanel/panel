<?php

namespace App\Deploy;

use App\Models\App;

/**
 * The starting .env text of a new Laravel app: what a container needs to run in production, with a
 * generated APP_KEY. The database variables are not here: the database link provides DB_CONNECTION,
 * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
 */
class LaravelEnvTemplate
{
    /**
     * @param  string|null  $domain  the first domain of the app, for APP_URL
     * @param  bool  $queue  a queue worker or Horizon will run: queued jobs go through a real queue
     * @param  bool  $redis  Redis is linked: cache, sessions and the queue use it (Horizon needs it)
     */
    public function generate(App $app, ?string $domain = null, bool $queue = false, bool $redis = false): string
    {
        $url = $domain !== null && $domain !== '' ? 'https://'.ltrim($domain, '*.') : 'http://localhost';
        $store = $redis ? 'redis' : 'file';
        // Jobs only go to a real queue when something will run them.
        $queueConnection = $queue ? ($redis ? 'redis' : 'database') : 'sync';
        $key = 'base64:'.base64_encode(random_bytes(32));

        return <<<ENV
        # The environment of {$app->name}. CommyPanel gives these to every container of the app.
        # Lines starting with # are comments. Change them here and press Save, then Apply changes.

        APP_NAME={$this->quote($app->name)}
        APP_ENV=production
        APP_KEY={$key}
        APP_DEBUG=false
        APP_URL={$url}

        # Logs go to the container's output, so they show on the Logs tab.
        LOG_CHANNEL=stderr
        LOG_LEVEL=info

        # DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD come from the
        # database link of this app. Add them here only to use a different database.

        SESSION_DRIVER={$store}
        CACHE_STORE={$store}
        QUEUE_CONNECTION={$queueConnection}

        BCRYPT_ROUNDS=12
        MAIL_MAILER=log

        ENV;
    }

    /**
     * Stores the text as the app's environment (its variables are derived from it).
     *
     * @return array{added: list<string>, changed: list<string>, removed: list<string>}
     */
    public function apply(App $app, string $text): array
    {
        return app(AppEnvironment::class)->replaceText($app, $text);
    }

    private function quote(string $value): string
    {
        return EnvText::quote($value);
    }
}
