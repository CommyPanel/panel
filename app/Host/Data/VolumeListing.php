<?php

namespace App\Host\Data;

final readonly class VolumeListing
{
    /**
     * @param  list<VolumeEntry>  $entries
     */
    public function __construct(public array $entries, public bool $truncated = false) {}
}
