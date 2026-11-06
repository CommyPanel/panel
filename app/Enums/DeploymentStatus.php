<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Starting = 'starting';
    case Active = 'active';
    case Superseded = 'superseded';
    case Failed = 'failed';
    case Stopped = 'stopped';
    /** A share of the visitors is on this release while it is being proven (a gradual rollout). */
    case Rollout = 'rollout';
    /** It went live, did clearly worse than the release before it, and traffic was moved back. */
    case RolledBack = 'rolled_back';

    public function isFinished(): bool
    {
        return in_array($this, [self::Active, self::Superseded, self::Failed, self::Stopped, self::RolledBack], true);
    }

    /**
     * The statuses of a deployment that is still being made or proven: a new one must wait for it.
     *
     * @return list<self>
     */
    public static function working(): array
    {
        return [self::Queued, self::Building, self::Starting, self::Rollout];
    }
}
