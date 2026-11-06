<?php

namespace App\Deploy;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Support\Facades\Cache;

class PortAllocator
{
    /**
     * Reserve a free localhost port for a deployment and store it on the
     * deployment. Serialised so concurrent deploys never get the same port.
     *
     * @throws DeploymentFailed
     */
    public function allocate(Deployment $deployment): int
    {
        return Cache::lock('deploy-port-allocation', 10)->block(10, function () use ($deployment): int {
            $used = Deployment::query()
                ->where(function ($query) {
                    $query->whereIn('status', [DeploymentStatus::Building, DeploymentStatus::Starting, DeploymentStatus::Active, DeploymentStatus::Rollout])
                        // The release a new one replaced stays running while the new one is watched.
                        ->orWhereIn('id', Deployment::query()->where('watch_state', 'watching')->whereNotNull('watch_previous_id')->select('watch_previous_id'));
                })
                ->whereNotNull('host_port')
                ->pluck('host_port')
                ->all();

            $start = (int) config('commypanel.deploy.port_range_start');
            $end = (int) config('commypanel.deploy.port_range_end');

            for ($port = $start; $port <= $end; $port++) {
                if (! in_array($port, $used, true) && $this->isFree($port)) {
                    $deployment->forceFill(['host_port' => $port])->save();

                    return $port;
                }
            }

            throw new DeploymentFailed("No free port left between {$start} and {$end}.");
        });
    }

    protected function isFree(int $port): bool
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
