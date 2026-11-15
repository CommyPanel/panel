<?php

namespace App\Deploy\Stacks;

class StackDetector
{
    /** @var list<StackDefinition> */
    private array $stacks;

    /**
     * Order matters: a repository Dockerfile always wins, then the most
     * specific stack markers.
     *
     * @param  list<StackDefinition>|null  $stacks
     */
    public function __construct(?array $stacks = null)
    {
        $this->stacks = $stacks ?? [
            new TemplateStack,
            new DockerfileStack,
            new DotnetStack,
            new GoStack,
            new NodeStack,
        ];
    }

    /**
     * @throws DetectionFailed
     */
    public function plan(string $path, int $port): BuildPlan
    {
        if (! is_dir($path)) {
            throw new DetectionFailed('The source directory does not exist.');
        }

        foreach ($this->stacks as $stack) {
            if ($stack->matches($path)) {
                return $stack->plan($path, $port);
            }
        }

        throw new DetectionFailed('Could not detect the app type. CommyPanel supports .NET, Go and Node.js projects, or any repository with a Dockerfile.');
    }
}
