<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

/**
 * The repository ships its own Dockerfile, which always wins.
 */
class DockerfileStack implements StackDefinition
{
    public function stack(): Stack
    {
        return Stack::Dockerfile;
    }

    public function matches(string $path): bool
    {
        return SourceTree::has($path, 'Dockerfile');
    }

    public function plan(string $path, int $port, array $options = []): BuildPlan
    {
        return new BuildPlan(Stack::Dockerfile, null, null, ['Using the Dockerfile from the repository.']);
    }
}
