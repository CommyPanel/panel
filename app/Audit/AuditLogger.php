<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuditLogger
{
    /** Keys that must never be stored, whatever a caller passes. */
    private const REDACTED = ['password', 'password_confirmation', 'current_password', 'token', 'secret', 'code', 'recovery_code'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $action, ?User $actor = null, array $metadata = [], ?Model $subject = null): AuditLog
    {
        $actor ??= $this->request->user();
        // Artisan has no real client; the test suite does issue HTTP requests.
        $noClient = app()->runningInConsole() && ! app()->runningUnitTests();

        // A call made with an API token says which one (its name, never the secret).
        $token = $actor?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && request()->bearerToken() !== null) {
            $metadata['via_token'] = $token->name;
        }

        return AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'user_email' => $actor?->email,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $this->clean($metadata) ?: null,
            'ip_address' => $noClient ? null : $this->request->ip(),
            'user_agent' => $noClient ? null : mb_substr((string) $this->request->userAgent(), 0, 500),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function clean(array $metadata): array
    {
        return array_diff_key($metadata, array_flip(self::REDACTED));
    }
}
