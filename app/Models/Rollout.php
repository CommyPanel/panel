<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A gradual rollout: a new release gets a growing share of the visitors, step by step, while
 * the release it replaces keeps the rest. It ends by being promoted (everyone gets it),
 * aborted by a person, or rolled back because it did clearly worse.
 *
 * @property int $id
 * @property int $app_id
 * @property int $deployment_id
 * @property int|null $previous_deployment_id
 * @property list<int> $steps
 * @property int $step_index
 * @property int $percent
 * @property int $hold_minutes
 * @property bool $auto
 * @property string $state
 * @property Carbon $step_started_at
 * @property int $strikes
 * @property string|null $note
 * @property Carbon|null $finished_at
 */
class Rollout extends Model
{
    public const RUNNING = 'running';

    /** The step is done and a person has to decide: promote or abort. */
    public const WAITING = 'waiting';

    public const PROMOTED = 'promoted';

    public const ABORTED = 'aborted';

    public const ROLLED_BACK = 'rolled_back';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'auto' => 'boolean',
            'step_started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->state, [self::RUNNING, self::WAITING], true);
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * The new release.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * The release being replaced, which keeps the rest of the visitors.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'previous_deployment_id');
    }

    /**
     * The rollout that is under way for an app, if any.
     */
    public static function activeFor(App $app): ?self
    {
        return self::query()->where('app_id', $app->id)->whereIn('state', [self::RUNNING, self::WAITING])->latest('id')->first();
    }
}
