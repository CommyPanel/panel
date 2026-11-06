<?php

namespace App\Traffic;

use App\Host\Data\TrafficStat;

/**
 * Decides, from request counts alone, whether a new release is clearly worse than the one it
 * replaced. It is deliberately slow to blame: it needs enough requests, several real
 * failures, and a rate that is both clearly above the reference's and clearly above zero.
 * A release is compared with the previous one (not with a fixed limit), so an app that
 * always has a few failing requests does not trip it.
 */
class TrafficJudge
{
    /** The new release has to have served this many requests before anyone judges it. */
    public const MIN_REQUESTS = 30;

    /** And this many of them must have failed (5xx) before the error rate counts. */
    public const MIN_ERRORS = 5;

    /** The release it is compared with must have served this many to be a reference. */
    public const REFERENCE_MIN = 20;

    /** Failure rate that is bad on its own when there is no reference to compare with. */
    public const ABSOLUTE_ERROR_RATE = 0.05;

    /** It must be worse by at least this much (as a rate, 0.02 is two percentage points)... */
    public const ERROR_MARGIN = 0.02;

    /** ...and by at least this factor. */
    public const ERROR_FACTOR = 2.0;

    /** Latency is only judged with this many requests on the new release. */
    public const LATENCY_MIN_REQUESTS = 50;

    /** A p95 this many times the reference's, and this many milliseconds above it, is bad. */
    public const LATENCY_FACTOR = 2.0;

    public const LATENCY_MARGIN_MS = 500.0;

    public function judge(?TrafficStat $new, ?TrafficStat $reference): Verdict
    {
        $served = $new === null ? 0 : $new->requests;

        if ($new === null || $served < self::MIN_REQUESTS) {
            return new Verdict(Verdict::UNKNOWN, "Not enough requests yet to judge ({$served} of ".self::MIN_REQUESTS.').', $new, $reference);
        }

        $reference = $reference !== null && $reference->requests >= self::REFERENCE_MIN ? $reference : null;
        $rate = $new->errorRate();
        $refRate = $reference === null ? null : $reference->errorRate();

        if ($new->errors >= self::MIN_ERRORS) {
            $worse = $refRate === null
                ? $rate >= self::ABSOLUTE_ERROR_RATE
                : $rate >= $refRate + self::ERROR_MARGIN && $rate >= $refRate * self::ERROR_FACTOR;

            if ($worse) {
                $against = $refRate === null ? '' : ' (the previous release: '.$this->percent($refRate).')';

                return new Verdict(Verdict::BAD, 'The error rate (5xx) is '.$this->percent($rate)." over {$new->requests} requests{$against}.", $new, $reference);
            }
        }

        if ($reference !== null && $served >= self::LATENCY_MIN_REQUESTS && $reference->p95 > 0
            && $new->p95 >= max($reference->p95 * self::LATENCY_FACTOR, $reference->p95 + self::LATENCY_MARGIN_MS)) {
            return new Verdict(Verdict::BAD, "The slowest 5% of requests take {$this->ms($new->p95)} or more (the previous release: {$this->ms($reference->p95)}).", $new, $reference);
        }

        $compared = $reference === null ? '' : ' (previous release: '.$this->percent($reference->errorRate()).')';

        return new Verdict(Verdict::OK, "{$served} requests, ".$this->percent($rate)." errors{$compared}, p95 {$this->ms($new->p95)}.", $new, $reference);
    }

    private function percent(float $rate): string
    {
        return preg_replace('/\.0$/', '', number_format($rate * 100, 1, '.', '')).'%';
    }

    private function ms(float $ms): string
    {
        return number_format($ms, 0, '.', '').' ms';
    }
}
