<?php

namespace App\Metrics;

use App\Host\Data\ContainerUsage;
use App\Host\Data\ResourceUsage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\DatabaseServer;
use App\Models\Deployment;
use Illuminate\Support\Facades\Cache;

/**
 * What the panel tells a Prometheus: how busy the server and its containers are (from the same
 * readings the Usage tab shows), whether each app and database server is up, and when each app's
 * certificate ends. Only numbers and names: no variables, no logs, no addresses, no secrets.
 *
 * A token limited to some apps gets only those apps' series. The server's own, the database servers'
 * and the containers that belong to no app need a token that is not limited.
 */
class ServerMetrics
{
    /** Seconds the agent's reading is reused, so several scrapers do not each trigger one. */
    private const CACHE_SECONDS = 10;

    public function __construct(private readonly HostDriver $host) {}

    /**
     * @param  list<string>  $onlyApps  slugs the token is limited to; empty for every app
     */
    public function render(array $onlyApps = []): string
    {
        $all = $onlyApps === [];
        $text = new PrometheusText;

        $text->gauge('commypanel_info', 'The CommyPanel version that answers (always 1).', 1, ['version' => (string) config('commypanel.version')]);

        $usage = $this->usage();

        if ($all) {
            $text->gauge('commypanel_agent_up', 'Whether the host agent answered (1) or not (0).', $usage === null ? 0 : 1);
        }

        $apps = App::query()->whereNull('setup_step')->with(['latestDeployment', 'certificate'])->orderBy('slug')->get()
            ->filter(fn (App $app) => $all || in_array($app->slug, $onlyApps, true));

        foreach ($apps as $app) {
            $status = $app->status();

            $text->gauge('commypanel_app_up', 'Whether the app is running (1) or not (0).', $status->value === 'running' ? 1 : 0, ['app' => $app->slug]);
            $text->gauge('commypanel_app_status_info', 'The app\'s state (always 1); the state is in the label.', 1, ['app' => $app->slug, 'status' => $status->value]);

            if ($app->certificate?->expires_at !== null && $app->certificate->isUsable()) {
                $text->gauge('commypanel_app_certificate_expiry_timestamp_seconds', 'When the app\'s HTTPS certificate ends, as a Unix time.', $app->certificate->expires_at->getTimestamp(), ['app' => $app->slug]);
            }
        }

        if ($all) {
            foreach (DatabaseServer::query()->orderBy('slug')->get() as $server) {
                $text->gauge('commypanel_database_server_up', 'Whether the database server is running (1) or not (0).', $server->isRunning() ? 1 : 0, [
                    'server' => $server->slug, 'engine' => $server->engine->value, 'version' => (string) $server->version,
                ]);
            }
        }

        if ($usage !== null) {
            if ($all && $usage->host['ready']) {
                $this->host($text, $usage);
            }

            $this->containers($text, $usage, $all ? null : $onlyApps);
        }

        return $text->render();
    }

    /** The agent's reading, or null when it cannot be had (kept for a few seconds either way). */
    private function usage(): ?ResourceUsage
    {
        $reading = Cache::remember('metrics.usage', self::CACHE_SECONDS, function (): array {
            try {
                return ['usage' => $this->host->resourceUsage(false)];
            } catch (HostOperationFailed) {
                return ['usage' => null];
            }
        });

        return $reading['usage'];
    }

    private function host(PrometheusText $text, ResourceUsage $usage): void
    {
        $h = $usage->host;

        $text->gauge('commypanel_host_cpu_usage_ratio', 'How busy the server\'s processors are, from 0 to 1 (of all of them).', $h['cpu_percent'] / 100);
        $text->gauge('commypanel_host_cpus', 'The number of processors.', $h['cpus']);
        $text->gauge('commypanel_host_memory_total_bytes', 'The server\'s memory.', $h['mem_total']);
        $text->gauge('commypanel_host_memory_used_bytes', 'Memory in use on the server.', $h['mem_used']);
        $text->gauge('commypanel_host_disk_total_bytes', 'The size of the disk that holds the data.', $h['disk_total']);
        $text->gauge('commypanel_host_disk_used_bytes', 'Space used on that disk.', $h['disk_used']);
        $text->gauge('commypanel_host_network_receive_bytes_per_second', 'Bytes the server receives per second.', $h['net_rx_bps']);
        $text->gauge('commypanel_host_network_transmit_bytes_per_second', 'Bytes the server sends per second.', $h['net_tx_bps']);
    }

    /**
     * @param  list<string>|null  $onlyApps  null for every container, else only those of these apps
     */
    private function containers(PrometheusText $text, ResourceUsage $usage, ?array $onlyApps): void
    {
        $owners = Deployment::query()->with('app:id,slug')
            ->whereIn('container_name', array_map(fn (ContainerUsage $c) => $c->name, $usage->containers))
            ->get()->keyBy('container_name');

        foreach ($usage->containers as $c) {
            $app = $owners->get($c->name)?->app?->slug;
            $kind = $app !== null ? 'app' : (str_starts_with($c->name, 'db-') ? 'database' : 'other');

            if ($onlyApps !== null && ($app === null || ! in_array($app, $onlyApps, true))) {
                continue;
            }

            $labels = ['container' => $c->name, 'app' => $app ?? '', 'kind' => $kind];

            $text->gauge('commypanel_container_cpu_usage_ratio', 'CPU the container uses, where 1 is one full core.', $c->cpuPercent / 100, $labels);
            $text->gauge('commypanel_container_memory_used_bytes', 'Memory the container uses.', $c->memUsed, $labels);

            if ($c->memLimit > 0) {
                $text->gauge('commypanel_container_memory_limit_bytes', 'The container\'s memory limit.', $c->memLimit, $labels);
            }

            $text->gauge('commypanel_container_network_receive_bytes_per_second', 'Bytes the container receives per second.', $c->netRxBps, $labels);
            $text->gauge('commypanel_container_network_transmit_bytes_per_second', 'Bytes the container sends per second.', $c->netTxBps, $labels);
        }
    }
}
