<?php

namespace App\Host\Data;

/**
 * The start of a file as text. A binary file has no content.
 */
final readonly class VolumeFilePreview
{
    public function __construct(
        public int $size,
        public string $content,
        public bool $binary = false,
        public bool $truncated = false,
    ) {}
}
