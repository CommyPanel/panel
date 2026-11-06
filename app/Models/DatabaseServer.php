<?php

namespace App\Models;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Host\Data\DatabaseTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property bool $tls_enabled
 * @property string|null $tls_ca
 * @property int|null $remote_port
 * @property string|null $remote_status
 * @property string|null $remote_error
 * @property list<string>|null $remote_allow
 * @property Carbon|null $remote_expires_at
 * @property bool $remote_forever
 * @property Carbon|null $remote_opened_at
 * @property string|null $remote_opened_by
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
            'tls_enabled' => 'boolean',
            'remote_allow' => 'array',
            'remote_expires_at' => 'datetime',
            'remote_forever' => 'boolean',
            'remote_opened_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ManagedDatabase, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    /** Whether the public port is open (or meant to be: it is re-opened if the agent lost it). */
    public function remoteOpen(): bool
    {
        return $this->remote_port !== null;
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
