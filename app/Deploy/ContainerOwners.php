<?php

namespace App\Deploy;

use App\Models\Deployment;
use Illuminate\Support\Collection;

/**
 * Which release (and so which app) a container belongs to: the web container of a release, or one of its
 * extra processes (workers, scheduler).
 */
final class ContainerOwners
{
    /**
     * @param  list<string>  $names  container names
     * @return Collection<string, Deployment> by container name, the app loaded with its slug and name
     */
    public static function for(array $names): Collection
    {
        $owners = Deployment::query()->with('app:id,slug,name')->whereIn('container_name', $names)->get()->keyBy('container_name');
        $wanted = array_flip($names);

        // The extra processes of every release that has some (only apps that use them).
        foreach (Deployment::query()->with('app:id,slug,name')->whereNotNull('processes')->get() as $deployment) {
            foreach ((array) $deployment->processes as $entry) {
                foreach ((array) ($entry['containers'] ?? []) as $container) {
                    if (isset($wanted[$container])) {
                        $owners->put($container, $deployment);
                    }
                }
            }
        }

        return $owners;
    }
}
