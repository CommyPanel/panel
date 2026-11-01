<?php

namespace App\Models;

use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property int $number
 * @property DeploymentStatus $status
 * @property DeploymentStage|null $stage
 * @property string|null $commit_sha
 * @property string|null $commit_message
 * @property string|null $release_command
 * @property string|null $image_tag
 * @property bool $image_available
 * @property int|null $rolled_back_from_id
 * @property string|null $container_name
 * @property int|null $host_port
 * @property string|null $log
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'stage' => DeploymentStage::class,
            'image_available' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
