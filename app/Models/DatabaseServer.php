<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Host\Data\DatabaseTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A database server CommyPanel runs as a container: MariaDB, MySQL or PostgreSQL.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property DatabaseEngine $engine
 * @property string $version
 * @property DatabaseServerStatus $status
 * @property string|null $error
 * @property string $root_password
 * @property int|null $memory_limit_mb
 */
class DatabaseServer extends Model
{
    protected $guarded = [];

    protected $hidden = ['root_password'];

    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'status' => DatabaseServerStatus::class,
            'root_password' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<ManagedDatabase, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    public function containerName(): string
    {
        return 'db-'.$this->slug;
    }

    /** The server's own network. Apps are never attached to it; each link has a network of its own. */
    public function networkName(): string
    {
        return 'commypanel-db-'.$this->slug;
    }

    public function image(): string
    {
        return $this->engine->image($this->version);
    }

    public function target(): DatabaseTarget
    {
        return new DatabaseTarget($this->containerName(), $this->engine->value, $this->root_password);
    }

    public function isRunning(): bool
    {
        return $this->status === DatabaseServerStatus::Running;
    }
}
