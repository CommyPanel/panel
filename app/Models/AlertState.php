<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Where something was when the monitor last looked (an app, a database server, the disk, a
 * certificate), so a message is sent when it changes and not on every look.
 *
 * @property int $id
 * @property string $subject
 * @property string $state
 * @property Carbon|null $since
 * @property int $strikes
 * @property Carbon|null $notified_at
 */
class AlertState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['since' => 'datetime', 'notified_at' => 'datetime'];
    }

    public static function for(string $subject): self
    {
        return self::query()->firstOrNew(['subject' => $subject], ['state' => 'ok', 'strikes' => 0]);
    }
}
