<?php

namespace App\Enums;

enum AppStatus: string
{
    /** The creation wizard has not been finished. */
    case Setup = 'setup';
    case New = 'new';
    case Deploying = 'deploying';
    case Running = 'running';
    case Failed = 'failed';
    /** The live container is stopped by the user; its domains stay configured. */
    case Stopped = 'stopped';
    /** Deactivated by the user: nothing runs and no domain is served. */
    case Deactivated = 'deactivated';
}
