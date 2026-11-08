<?php

namespace App\Enums;

/**
 * Where an app is in the creation wizard. Step 1 (basics) is the form that
 * creates the draft, so it has no case here.
 */
enum SetupStep: string
{
    case Source = 'source';
    /** Quick-install apps choose their database here instead of giving repository access. */
    case Database = 'database';
    case Settings = 'settings';
    case Domain = 'domain';
}
