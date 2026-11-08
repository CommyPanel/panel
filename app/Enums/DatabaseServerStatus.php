<?php

namespace App\Enums;

enum DatabaseServerStatus: string
{
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => __('Starting up'),
            self::Running => __('Running'),
            self::Stopped => __('Stopped'),
            self::Failed => __('Failed'),
        };
    }
}
