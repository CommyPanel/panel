<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One database on a server, with the one user that owns it.
 *
 * @property int $id
 * @property int $database_server_id
 * @property string $name
 * @property string $username
 * @property string $password
 * @property string|null $restore_status
 * @property string|null $restore_from
 * @property string|null $restore_error
 */
class ManagedDatabase extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    /**
     * @return BelongsTo<DatabaseServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class, 'database_server_id');
    }

    /**
     * @return HasMany<AppDatabaseLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AppDatabaseLink::class);
    }
}
