<?php

namespace App\Enums;

/**
 * The steps a deployment goes through, in order. Release only runs when the
 * app has a release command. A rollback starts at Start, because the image
 * already exists and its release command already ran.
 */
enum DeploymentStage: string
{
    case Fetch = 'fetch';
    case Build = 'build';
    case Release = 'release';
    case Start = 'start';
    case Health = 'health';
    case Switch = 'switch';
    case Done = 'done';
}
