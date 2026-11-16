<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;
use App\Models\App;

/**
 * One Elasticsearch node with security on: nobody gets in without the generated `elastic` password.
 * Memory mapping is switched off in its settings, so it starts even when the host's
 * `vm.max_map_count` is still the Linux default (which a container cannot raise); raising it on the
 * host gives better speed.
 */
class Elasticsearch extends QuickApp
{
    public const IMAGE = 'docker.elastic.co/elasticsearch/elasticsearch:8.19.0';

    public function id(): string
    {
        return 'elasticsearch';
    }

    public function name(): string
    {
        return 'Elasticsearch';
    }

    public function description(): string
    {
        return __('Search and analytics engine, one node with a password. Needs about 2 GB of memory.');
    }

    protected function image(): string
    {
        return self::IMAGE;
    }

    public function containerPort(): int
    {
        return 9200;
    }

    public function deployStrategy(): DeployStrategy
    {
        // It cannot run twice on the same data, so the old version has to stop before the new one starts.
        return DeployStrategy::StopFirst;
    }

    public function healthPath(): string
    {
        // Security is on, so the answer is 401: below 500 means it is up.
        return '/';
    }

    public function healthTimeout(): ?int
    {
        return 240;
    }

    public function minimumMemoryMb(): ?int
    {
        // its Java heap is 1 GB: below this the system kills it for lack of memory.
        return 1536;
    }

    public function memoryLimitMb(): ?int
    {
        return 2048;
    }

    public function volumePaths(): array
    {
        return ['/usr/share/elasticsearch/data'];
    }

    public function warnings(): array
    {
        return [
            __('Elasticsearch needs about 2 GB of memory for itself (1 GB of that is its Java heap). On a smaller server other apps may be stopped by the system.'),
            __('For best speed set vm.max_map_count=262144 on the server (run sysctl -w vm.max_map_count=262144 and add the line to /etc/sysctl.d/99-elasticsearch.conf). A container cannot change it. Elasticsearch starts without it, using a slower way of reading its files.'),
        ];
    }

    public function environment(array $settings): array
    {
        return [
            'ELASTIC_PASSWORD' => $this->secret(24),
            'ES_JAVA_OPTS' => '-Xms1g -Xmx1g',
        ];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<DOCKER
        FROM {$this->image()}
        COPY --chown=1000:0 elasticsearch.yml /usr/share/elasticsearch/config/elasticsearch.yml

        DOCKER;

        $config = <<<'YAML'
        cluster.name: commypanel
        network.host: 0.0.0.0
        discovery.type: single-node
        node.store.allow_mmap: false
        xpack.security.enabled: true
        xpack.security.http.ssl.enabled: false
        xpack.security.transport.ssl.enabled: false

        YAML;

        return ['Dockerfile' => $dockerfile, 'elasticsearch.yml' => $config];
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('User'), 'elastic'),
            $this->row(__('Password'), $this->variable($app, 'ELASTIC_PASSWORD'), true),
        ];
    }
}
