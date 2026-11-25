<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lets one app reach one database. Its containers meet the database server on a
 * network made for this link alone.
 *
 * @property int $id
 * @property int $app_id
 * @property int $managed_database_id
 * @property string $env_prefix
 * @property string $env_style prefix (<PREFIX>_URL, _HOST ...) or laravel (DB_CONNECTION, DB_HOST, DB_DATABASE ...)
 */
class AppDatabaseLink extends Model
{
    protected static function booted(): void
    {
        // The running container was made without this change: the app has changes to apply.
        $touch = fn (self $m) => App::query()->whereKey($m->app_id)->update(['config_changed_at' => now()]);
        static::saved($touch);
        static::deleted($touch);
    }

    public const STYLE_PREFIX = 'prefix';

    public const STYLE_LARAVEL = 'laravel';

    /** The prefix a Laravel-style link is made with, so a second one can not take its place. */
    public const LARAVEL_PREFIX = 'DB';

    protected $guarded = [];

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return BelongsTo<ManagedDatabase, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'managed_database_id');
    }

    /** Whether the app gets Laravel's own variable names (DB_HOST, DB_DATABASE ...) instead of <PREFIX>_HOST. */
    public function isLaravelStyle(): bool
    {
        return $this->env_style === self::STYLE_LARAVEL;
    }

    public function networkName(): string
    {
        return 'commypanel-link-d'.$this->id;
    }
}
