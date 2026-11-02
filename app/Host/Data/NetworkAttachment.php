<?php

namespace App\Host\Data;

/**
 * An extra network for a container, and the names it answers to there.
 */
final readonly class NetworkAttachment
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(public string $name, public array $aliases = [])
    {
        ResourceName::assertValid($name);

        foreach ($aliases as $alias) {
            ResourceName::assertValid($alias);
        }
    }
}
