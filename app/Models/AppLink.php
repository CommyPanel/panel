<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lets one app (`app`) reach another (`target`). Their containers meet on a
 * network made for this link alone, where the target answers to its own name.
 *
 * @property int $id
 * @property int $app_id
 * @property int $target_app_id
 * @property string $env_prefix
 */
class AppLink extends Model
{
    protected static function booted(): void
    {
        // The running container was made without this change: the app has changes to apply.
        $touch = fn (self $m) => App::query()->whereKey($m->app_id)->update(['config_changed_at' => now()]);
        static::saved($touch);
        static::deleted($touch);
    }

    protected $guarded = [];

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(App::class, 'target_app_id');
    }

    public function networkName(): string
    {
        return 'commypanel-link-a'.$this->id;
    }
}
