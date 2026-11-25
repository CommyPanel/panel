<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

final readonly class BuildPlan
{
    /**
     * @param  string|null  $dockerfile  contents to write, or null to use the repository's own Dockerfile
     * @param  string|null  $dockerignore  contents to write when the repository has no .dockerignore
     * @param  list<string>  $notes  human-readable decisions, shown in the build log
     * @param  array<string, string>  $files  extra files for the build context, by path relative to it (for example `.commypanel/entrypoint.sh`); written next to the Dockerfile
     */
    public function __construct(
        public Stack $stack,
        public ?string $dockerfile,
        public ?string $dockerignore = null,
        public array $notes = [],
        public array $files = [],
    ) {}
}
