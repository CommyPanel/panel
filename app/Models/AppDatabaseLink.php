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
 */
class AppDatabaseLink extends Model
{
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

    public function networkName(): string
    {
        return 'commypanel-link-d'.$this->id;
    }
}
