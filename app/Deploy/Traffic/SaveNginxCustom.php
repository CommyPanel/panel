<?php

namespace App\Deploy\Traffic;

use App\Audit\AuditLogger;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\User;
use Throwable;

/**
 * Saves an app's custom nginx directives. The agent checks them against its
 * allow-list first, then nginx itself tests the whole config when the site is
 * applied; if either refuses, nothing is saved and the previous config stays
 * in place. The text is stored as typed (comments included) and re-applied by
 * AppRouting on every deploy, so it survives domain and release changes.
 */
class SaveNginxCustom
{
    public const MAX_LENGTH = 16000;

    public function __construct(
        private readonly HostDriver $host,
        private readonly AppRouting $routing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool whether the site exists, so the change is live now; false
     *              when it is only saved until the app has a domain and a live release
     *
     * @throws HostOperationFailed when the agent or nginx refuses it
     */
    public function __invoke(App $app, ?string $custom, ?User $by = null): bool
    {
        $custom = self::normalize($custom);
        $previous = $app->nginx_custom;

        if ($custom === $previous) {
            return $this->routing->siteFor($app) !== null;
        }

        // Explains a refusal even when the app has no site to test it on yet.
        if ($custom !== null) {
            $this->host->checkNginxCustom($custom);
        }

        $app->nginx_custom = $custom;

        try {
            $this->routing->sync($app);
        } catch (Throwable $e) {
            $app->nginx_custom = $previous;

            throw $e;
        }

        $app->save();
        $this->audit->record('app.nginx_updated', $by, ['app' => $app->slug, 'before' => $previous, 'after' => $custom], $app);

        return $this->routing->siteFor($app) !== null;
    }

    /**
     * Unix line endings, no trailing blank space, and null for nothing at all.
     */
    public static function normalize(?string $custom): ?string
    {
        $custom = rtrim(str_replace(["\r\n", "\r"], "\n", (string) $custom));

        return trim($custom) === '' ? null : $custom;
    }
}
