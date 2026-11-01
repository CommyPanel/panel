<?php

namespace App\Models;

use App\Host\Data\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A folder of an app that survives deployments: mounted at `path` in every
 * container the app runs, and kept in <data dir>/volumes/<slug>/<name>.
 *
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property string $path
 */
class AppVolume extends Model
{
    protected $guarded = [];

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
