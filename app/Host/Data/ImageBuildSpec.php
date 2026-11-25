<?php

namespace App\Host\Data;

final readonly class ImageBuildSpec
{
    public function __construct(
        public string $tag,
        public string $contextPath,
        public string $dockerfile = 'Dockerfile',
        /** Build every step again and fetch the base images anew, instead of reusing earlier layers. */
        public bool $fresh = false,
    ) {}
}
