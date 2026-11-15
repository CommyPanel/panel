<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A person's signed-in browsers, read from the session table. A raw session id is a credential
 * and never leaves this class: pages get a keyed hash of it (the handle) and revoking takes the
 * handle, looked up among that one user's sessions only. Only the database session driver can be
 * listed; with another driver there is nothing to show.
 */
class ActiveSessions
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<array{handle: string, ip: string|null, browser: string|null, os: string|null, user_agent: string|null, last_active: string, current: bool}>
     */
    public function for(User $user, ?string $currentId = null): array
    {
        if (! $this->supported()) {
            return [];
        }

        $lifetime = (int) config('session.lifetime', 120) * 60;

        $rows = $this->table()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', time() - $lifetime)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return array_values($rows->map(function (object $row) use ($currentId): array {
            $agent = UserAgent::describe($row->user_agent);

            return [
                'handle' => $this->handle((string) $row->id),
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'browser' => $agent['browser'],
                'os' => $agent['os'],
                'user_agent' => $row->user_agent === null ? null : mb_substr((string) $row->user_agent, 0, 300),
                'last_active' => date(DATE_ATOM, (int) $row->last_activity),
                'current' => $currentId !== null && hash_equals((string) $row->id, $currentId),
            ];
        })->all());
    }

    /** Signs one browser of $user out. Returns whether it existed (and was not the current one). */
    public function revoke(User $user, string $handle, ?string $exceptId = null): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $ids = $this->table()->where('user_id', $user->getKey())->pluck('id');

        foreach ($ids as $id) {
            if (! hash_equals($this->handle((string) $id), $handle)) {
                continue;
            }

            if ($exceptId !== null && hash_equals((string) $id, $exceptId)) {
                return false;
            }

            return $this->table()->where('user_id', $user->getKey())->where('id', $id)->delete() > 0;
        }

        return false;
    }

    /** Signs $user out everywhere except $exceptId. Returns how many were ended. */
    public function revokeAll(User $user, ?string $exceptId = null): int
    {
        if (! $this->supported()) {
            return 0;
        }

        $query = $this->table()->where('user_id', $user->getKey());

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->delete();
    }

    public function handle(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    private function table(): Builder
    {
        return $this->connection()->table((string) config('session.table', 'sessions'));
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('session.connection'));
    }
}
