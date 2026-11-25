<?php

namespace App\Enums;

/**
 * Where an app is in the creation wizard. Step 1 (basics) is the form that
 * creates the draft, so it has no case here.
 */
enum SetupStep: string
{
    case Source = 'source';
    case Settings = 'settings';
    // Laravel apps only, between Settings and Domain, in this order.
    case Database = 'database';
    case Services = 'services';
    case Volumes = 'volumes';
    case Domain = 'domain';
    // Laravel apps only: the last look (variables, release script) before the first deployment.
    case Review = 'review';
}
