<?php

namespace App\Dns;

use App\Models\Domain;

/**
 * Domains that are known to the panel but belong to no app (their app was deleted), so a new app
 * can pick one instead of typing it again.
 */
class FreeDomains
{
    public function __construct(private readonly PanelDomain $panel) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach (Domain::query()->whereNull('app_id')->orderBy('name')->pluck('name') as $name) {
            if (! $this->panel->isTaken((string) $name)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
