<?php

namespace App\Presenters;

use App\Host\Data\ContainerInfo;
use App\Host\Data\NetworkInfo;

/**
 * Shapes containers and networks for the React pages.
 */
class ContainerPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function container(ContainerInfo $c): array
    {
        return [
            'name' => $c->name,
            'image' => $c->image,
            'state' => $c->state,
            'status' => $c->status,
            'created' => $c->created,
            'networks' => $c->networks,
            'ports' => $c->ports,
            'mounts' => $c->mounts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function network(NetworkInfo $n): array
    {
        return [
            'name' => $n->name,
            'driver' => $n->driver,
            'subnet' => $n->subnet,
            'gateway' => $n->gateway,
            'internal' => $n->internal,
            'containers' => $n->containers,
        ];
    }
}
