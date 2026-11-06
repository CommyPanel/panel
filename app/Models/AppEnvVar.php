<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property string $key
 * @property string $value
 */
class AppEnvVar extends Model
{
    protected static function booted(): void
    {
        // The running container was made without this change: the app has changes to apply.
        $touch = fn (self $m) => App::query()->whereKey($m->app_id)->update(['config_changed_at' => now()]);
        static::saved($touch);
        static::deleted($touch);
    }

    protected $guarded = [];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
