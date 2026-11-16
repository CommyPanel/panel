<?php

namespace App\Enums;

/**
 * How a new release replaces the running one.
 */
enum DeployStrategy: string
{
    /** The new version starts next to the old one and takes over when it answers (no downtime). */
    case Rolling = 'rolling';

    /**
     * The old version stops first, then the new one starts. For software that cannot run twice on the
     * same data (Redis, Elasticsearch, Garage): a short outage, and the old version is started
     * again if the new one fails.
     */
    case StopFirst = 'stop_first';

    public function label(): string
    {
        return match ($this) {
            self::Rolling => __('Zero downtime'),
            self::StopFirst => __('Stop the old version first'),
        };
    }
}
