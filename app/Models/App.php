<?php

namespace App\Models;

use App\Dns\PanelDomain;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property SetupStep|null $setup_step
 * @property Stack|null $stack
 * @property Stack|null $stack_hint
 * @property SourceType $source_type
 * @property string|null $git_url
 * @property string $git_branch
 * @property string|null $deploy_key_private
 * @property string|null $deploy_key_public
 * @property string|null $git_token
 * @property string|null $git_token_username
 * @property string|null $webhook_id
 * @property string|null $webhook_secret
 * @property string|null $nginx_custom
 * @property Carbon|null $source_verified_at
 * @property int $container_port
 * @property string $health_path
 * @property string|null $release_command
 * @property int|null $memory_limit_mb
 * @property string|null $cpu_limit
 * @property int|null $active_deployment_id
 * @property Carbon|null $stopped_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use HasFactory;

    public const SLUG_MAX_LENGTH = 40;

    protected $guarded = [];

    protected $hidden = ['deploy_key_private', 'git_token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'setup_step' => SetupStep::class,
            'stack' => Stack::class,
            'stack_hint' => Stack::class,
            'source_verified_at' => 'datetime',
            'stopped_at' => 'datetime',
            'source_type' => SourceType::class,
            'deploy_key_private' => 'encrypted',
            'git_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany('number');
    }

    /**
     * Derived from the loaded latestDeployment relation, so list pages should
     * eager-load it.
     */
    public function status(): AppStatus
    {
        $latest = $this->latestDeployment;

        return match (true) {
            $this->setup_step !== null => AppStatus::Setup,
            $latest !== null && ! $latest->status->isFinished() => AppStatus::Deploying,
            $this->active_deployment_id !== null && $this->stopped_at !== null => AppStatus::Stopped,
            $this->active_deployment_id !== null => AppStatus::Running,
            $latest?->status === DeploymentStatus::Stopped => AppStatus::Deactivated,
            $latest?->status === DeploymentStatus::Failed => AppStatus::Failed,
            default => AppStatus::New,
        };
    }

    /**
     * Whether a deployment is queued or in progress.
     */
    public function isDeploying(): bool
    {
        return $this->deployments()
            ->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::Building, DeploymentStatus::Starting])
            ->exists();
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function activeDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'active_deployment_id');
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<AppVolume, $this>
     */
    public function volumes(): HasMany
    {
        return $this->hasMany(AppVolume::class);
    }

    /**
     * @return HasMany<AppEnvVar, $this>
     */
    public function envVars(): HasMany
    {
        return $this->hasMany(AppEnvVar::class);
    }

    /**
     * Environment for the container: user variables as key => decrypted value.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return $this->envVars->pluck('value', 'key')->all();
    }

    /**
     * A unique, container-safe slug derived from a display name.
     */
    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = Str::limit($base === '' ? 'app' : $base, self::SLUG_MAX_LENGTH - 5, '');
        $base = trim($base, '-');
        $base = strlen($base) < 2 ? 'app' : $base;

        // The panel's own nginx site and certificate use this name.
        if ($base === PanelDomain::SLUG) {
            $base = 'panel-app';
        }

        $slug = $base;
        $suffix = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Allocate the next per-app deployment number.
     */
    public function nextDeploymentNumber(): int
    {
        return ((int) $this->deployments()->max('number')) + 1;
    }
}
