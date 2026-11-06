<?php

namespace App\Host\Data;

use InvalidArgumentException;

/**
 * A gradual rollout on an nginx site: this share of the visitors (1 to 99 percent) is sent
 * to a second container, the rest keep the site's own upstream.
 */
final readonly class NginxRollout
{
    public function __construct(public int $port, public int $percent)
    {
        if ($port < 1024 || $port > 65535) {
            throw new InvalidArgumentException("Invalid rollout port [{$port}].");
        }

        if ($percent < 1 || $percent > 99) {
            throw new InvalidArgumentException("A rollout's share must be between 1 and 99 percent, not [{$percent}].");
        }
    }
}
