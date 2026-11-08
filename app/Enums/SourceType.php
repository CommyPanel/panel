<?php

namespace App\Enums;

enum SourceType: string
{
    case Git = 'git';
    case Upload = 'upload';
    /** A quick-install app: the build comes from a panel-owned template, not from the person's code. */
    case Template = 'template';
}
