<?php

namespace App\Deploy\Stacks;

use App\Enums\Stack;

interface StackDefinition
{
    public function stack(): Stack;

    /**
     * Whether the source directory looks like this stack.
     */
    public function matches(string $path): bool;

    /**
     * @param  array<string, mixed>  $options  the app's `stack_options` (per-stack choices such as the PHP version); a stack ignores what it does not know
     *
     * @throws DetectionFailed when the source matches but cannot be built automatically
     */
    public function plan(string $path, int $port, array $options = []): BuildPlan;
}
