<?php

namespace App\Models;

use App\Host\Data\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A folder of an app that survives deployments: mounted at `path` in every
 * container the app runs, and kept in <data dir>/volumes/<slug>/<name>.
 *
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property string $path
 * @property Carbon|null $locked_at
 */
class AppVolume extends Model
{
    protected static function booted(): void
    {
        // The running container was made without this change: the app has changes to apply.
        $touch = fn (self $m) => App::query()->whereKey($m->app_id)->update(['config_changed_at' => now()]);
        static::saved($touch);
        static::deleted($touch);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    /**
     * A locked volume cannot be removed from its app, and its files cannot be
     * deleted, with the app or on their own.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function hostPath(App $app): string
    {
        return rtrim((string) config('commypanel.data_path'), '/')."/volumes/{$app->slug}/{$this->name}";
    }

    public function mount(App $app): Volume
    {
        return new Volume($this->hostPath($app), $this->path);
    }
}
