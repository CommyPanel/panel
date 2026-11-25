<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property CertificateStatus $status
 * @property list<string>|null $domains
 * @property bool $staging
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property array{domains: list<string>, records: list<array{name: string, value: string}>, expires_at: string|null}|null $dns_pending
 * @property Carbon|null $last_attempt_at
 * @property string|null $last_error
 */
class Certificate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'domains' => 'array',
            'dns_pending' => 'array',
            'staging' => 'boolean',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Whether a certificate that is still valid is stored, whatever the state
     * of the latest attempt.
     */
    public function isUsable(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture() && $this->issued_at !== null;
    }
}
