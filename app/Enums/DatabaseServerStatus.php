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
            self::Provisioning => 'Starting up',
            self::Running => 'Running',
            self::Stopped => 'Stopped',
            self::Failed => 'Failed',
        };
    }
}
