<?php

namespace App\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Narrows the audit log: a time range, how risky the action was, and words that must appear in
 * who did it, what it was, or the IP address it came from (IPv4 or IPv6, in any spelling).
 */
final class AuditFilter
{
    /**
     * @param  list<string>  $severities  any of danger, warning, info; none means all
     */
    public function __construct(
        public readonly string $search = '',
        public readonly array $severities = [],
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated query string
     */
    public static function fromInput(array $input): self
    {
        $severities = array_values(array_intersect([Severity::INFO, Severity::WARNING, Severity::DANGER], (array) ($input['severity'] ?? [])));

        return new self(
            trim((string) ($input['q'] ?? '')),
            $severities,
            self::moment($input['from'] ?? null),
            self::moment($input['to'] ?? null),
        );
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->from !== null) {
            $query->where('created_at', '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->where('created_at', '<=', $this->to);
        }

        $this->severity($query);

        foreach (preg_split('/\s+/', $this->search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->where(fn (Builder $q) => $this->word($q, $word));
        }

        return $query;
    }

    /**
     * @param  Builder<AuditLog>  $query
     */
    private function severity(Builder $query): void
    {
        $wanted = $this->severities;

        if ($wanted === [] || count($wanted) === 3) {
            return;
        }

        $query->where(function (Builder $q) use ($wanted) {
            if (in_array(Severity::DANGER, $wanted, true)) {
                $q->orWhereIn('action', Severity::DANGER_ACTIONS);
            }

            if (in_array(Severity::WARNING, $wanted, true)) {
                $q->orWhereIn('action', Severity::WARNING_ACTIONS);
            }

            if (in_array(Severity::INFO, $wanted, true)) {
                // Routine is everything that is neither: actions nobody graded count as routine.
                $q->orWhereNotIn('action', [...Severity::DANGER_ACTIONS, ...Severity::WARNING_ACTIONS]);
            }
        });
    }

    /**
     * One word of the search: it matches the email, the action or the address.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function word(Builder $query, string $word): void
    {
        $word = mb_strtolower($word);
        $like = '%'.addcslashes($word, '\\%_').'%';

        $query->whereRaw("lower(coalesce(user_email, '')) like ? escape '\\'", [$like])
            ->orWhereRaw("lower(action) like ? escape '\\'", [$like])
            ->orWhereRaw("lower(coalesce(ip_address, '')) like ? escape '\\'", [$like]);

        // A whole address in another spelling (2001:0DB8:0:0::1, [::1], ::ffff:1.2.3.4) is the same address.
        $canonical = self::canonicalAddress($word);

        if ($canonical !== null && $canonical !== $word) {
            $query->orWhere('ip_address', $canonical);
        }
    }

    /** The address the way it is stored (compressed, lower case), or null if the text is not a whole address. */
    public static function canonicalAddress(string $text): ?string
    {
        $text = trim($text, " \t[]");

        if (filter_var($text, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($text);

        return $binary === false ? null : strtolower((string) inet_ntop($binary));
    }

    private static function moment(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
