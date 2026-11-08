<?php

namespace App\Models;

use App\Alerts\AlertKind;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * A place alerts are sent to. The address is the secret of the channel (a webhook address, a
 * topic, a bot token): encrypted, hidden, and never handed to a page, only its host name.
 *
 * @property int $id
 * @property string $name
 * @property AlertKind $kind
 * @property string $address
 * @property string|null $chat_id
 * @property list<string> $events
 * @property bool $enabled
 * @property Carbon|null $last_ok_at
 * @property string|null $last_error
 */
class AlertTarget extends Model
{
    public const MAX = 10;

    protected $guarded = [];

    protected $hidden = ['address'];

    protected function casts(): array
    {
        return [
            'kind' => AlertKind::class,
            'address' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
            'last_ok_at' => 'datetime',
        ];
    }

    /**
     * The address, or null when it cannot be read (the APP_KEY changed since it was saved).
     */
    public function secret(): ?string
    {
        try {
            $raw = (string) ($this->getAttributes()['address'] ?? '');

            return $raw === '' ? null : Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * The only part of the address that may be shown.
     */
    public function host(): string
    {
        if ($this->kind === AlertKind::Telegram) {
            return AlertKind::TELEGRAM_HOST;
        }

        $secret = $this->secret();

        return $secret === null ? '' : (string) parse_url($secret, PHP_URL_HOST);
    }

    /**
     * The chat id with all but its last characters hidden.
     */
    public function maskedChatId(): ?string
    {
        if ($this->chat_id === null || $this->chat_id === '') {
            return null;
        }

        return '…'.substr($this->chat_id, -3);
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
