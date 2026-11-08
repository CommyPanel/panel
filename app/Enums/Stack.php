<?php

namespace App\Enums;

enum Stack: string
{
    case Dockerfile = 'dockerfile';
    case Dotnet = 'dotnet';
    case Go = 'go';
    case Node = 'node';
    case Wordpress = 'wordpress';

    public function label(): string
    {
        return match ($this) {
            self::Dockerfile => 'Dockerfile',
            self::Dotnet => '.NET',
            self::Go => 'Go',
            self::Node => 'Node.js',
            self::Wordpress => 'WordPress',
        };
    }
}
