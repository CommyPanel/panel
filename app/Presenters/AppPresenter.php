<?php

namespace App\Presenters;

use App\Deploy\Source\GitProvider;
use App\Enums\AppStatus;
use App\Enums\CertificateStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SourceType;
use App\Models\App;
use App\Models\Deployment;
use App\Models\Domain;

/**
 * Shapes models into the arrays the React pages receive. Secrets (env values,
 * private deploy keys) are never included.
 */
class AppPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(App $app): array
    {
        $latest = $app->latestDeployment;

        return [
            'slug' => $app->slug,
            'name' => $app->name,
            'stack' => $app->stack?->label(),
            'status' => $app->status()->value,
            'locked' => $app->isLocked(),
            'source_type' => $app->source_type->value,
            'git_url' => $app->git_url,
            'git_branch' => $app->git_branch,
            'active_release' => $app->activeDeployment?->number,
            'last_deployed_at' => $latest?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(App $app): array
    {
        return [
            ...self::summary($app),
            'container_port' => $app->container_port,
            'health_path' => $app->health_path,
            'memory_limit_mb' => $app->memory_limit_mb,
            'cpu_limit' => $app->cpu_limit === null ? null : (float) $app->cpu_limit,
            'deploy_key_public' => $app->deploy_key_public,
            'git_token' => $app->git_token === null ? null : ['username' => $app->git_token_username],
            'token_help' => self::tokenHelp($app),
            'release_command' => $app->release_command,
            'volumes' => $app->volumes()->orderBy('id')->get()->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'path' => $v->path, 'locked' => $v->isLocked()])->all(),
            'deactivated_release' => $app->status() === AppStatus::Deactivated
                ? $app->deployments()->where('status', DeploymentStatus::Stopped)->max('number')
                : null,
            'url' => self::liveUrl($app),
            'certificate' => self::certificate($app),
            'domains' => $app->domains->sortBy('name')->map(fn (Domain $domain) => self::domain($domain))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function domain(Domain $domain): array
    {
        return [
            'id' => $domain->id,
            'name' => $domain->name,
            'status' => $domain->status->value,
            'status_label' => $domain->status->label(),
            'app' => $domain->app?->slug,
            'app_name' => $domain->app?->name,
            'redirect_to' => $domain->redirect_to,
            'tls' => self::domainTls($domain),
            'found' => ['a' => $domain->last_check['a'] ?? [], 'aaaa' => $domain->last_check['aaaa'] ?? []],
            'problems' => $domain->last_check['problems'] ?? [],
            'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployment(Deployment $deployment, bool $withLog = false): array
    {
        return [
            'number' => $deployment->number,
            'status' => $deployment->status->value,
            'stage' => $deployment->stage?->value,
            'finished' => $deployment->status->isFinished(),
            'commit' => $deployment->commit_sha === null ? null : substr($deployment->commit_sha, 0, 8),
            'commit_message' => $deployment->commit_message,
            'duration_seconds' => $deployment->finished_at !== null && $deployment->started_at !== null ? (int) $deployment->started_at->diffInSeconds($deployment->finished_at, true) : null,
            'is_rollback' => $deployment->rolled_back_from_id !== null,
            'has_release_command' => $deployment->release_command !== null,
            'can_roll_back' => $deployment->image_tag !== null && $deployment->image_available,
            'error' => $deployment->error,
            'created_at' => $deployment->created_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            ...($withLog ? ['log' => $deployment->log ?? ''] : []),
        ];
    }

    /**
     * Whether visitors get HTTPS on this domain: secured (on a usable
     * certificate), pending (being requested), failed, or none.
     */
    private static function domainTls(Domain $domain): string
    {
        $certificate = $domain->app?->certificate;

        if ($certificate === null || ! in_array($domain->name, $certificate->domains ?? [], true)) {
            return 'none';
        }

        return match (true) {
            $certificate->isUsable() => 'secured',
            $certificate->status === CertificateStatus::Issuing => 'pending',
            $certificate->status === CertificateStatus::Failed => 'failed',
            default => 'none',
        };
    }

    /**
     * How to create an access token on the app's Git host, for Git apps.
     *
     * @return array<string, mixed>|null
     */
    private static function tokenHelp(App $app): ?array
    {
        if ($app->source_type !== SourceType::Git || $app->git_url === null) {
            return null;
        }

        $provider = GitProvider::fromUrl($app->git_url);

        return [
            'provider' => $provider->label(),
            'username' => $provider->tokenUsername(),
            ...$provider->tokenInstructions($app->git_url),
        ];
    }

    /**
     * Where the running app can be visited, if it has a live release and a domain.
     */
    private static function liveUrl(App $app): ?string
    {
        $domain = $app->domains->sortBy('name')->first(fn (Domain $d) => $d->redirect_to === null);

        if ($app->active_deployment_id === null || $domain === null) {
            return null;
        }

        $secure = $app->certificate !== null && $app->certificate->isUsable();

        return ($secure ? 'https://' : 'http://').$domain->name;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function certificate(App $app): ?array
    {
        $certificate = $app->certificate;

        if ($certificate === null) {
            return null;
        }

        return [
            'status' => $certificate->status->value,
            'domains' => $certificate->domains ?? [],
            'staging' => $certificate->staging,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'last_error' => $certificate->last_error,
        ];
    }
}
