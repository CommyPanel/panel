<?php

namespace App\FastApps;

use App\Models\App;
use Illuminate\Validation\Rule;

/**
 * Kibana for one of the person's Elasticsearch apps. The two meet on a private link network. Kibana
 * must not use the `elastic` superuser, so its own `kibana_system` user gets a generated password:
 * a small script (run with the node that ships inside the image) sets it through Elasticsearch's
 * security API before Kibana starts, using the `elastic` password given for the first start only.
 */
class Kibana extends QuickApp
{
    public const IMAGE = 'docker.elastic.co/kibana/kibana:8.19.0';

    public function id(): string
    {
        return 'kibana';
    }

    public function name(): string
    {
        return 'Kibana';
    }

    public function description(): string
    {
        return __('Explore and chart the data in one of your Elasticsearch apps. Sign in with that app\'s password.');
    }

    protected function image(): string
    {
        return self::IMAGE;
    }

    public function fields(): array
    {
        return [new Field(
            key: 'elasticsearch',
            label: __('Elasticsearch app'),
            type: 'app',
            appTemplate: 'elasticsearch',
            hint: __('Kibana connects to this app over a private network. Install Elasticsearch first if you have none.'),
            rules: ['required', 'string', Rule::exists('apps', 'slug')->where('template', 'elasticsearch')->whereNull('setup_step')],
        )];
    }

    public function requires(): ?string
    {
        return 'elasticsearch';
    }

    public function containerPort(): int
    {
        return 5601;
    }

    public function healthPath(): string
    {
        // The login page answers while Kibana is still starting; the status answers 503 until it is ready.
        return '/api/status';
    }

    public function healthTimeout(): ?int
    {
        return 400;
    }

    public function minimumMemoryMb(): ?int
    {
        // it needs about 1 GB: below this the system kills it for lack of memory.
        return 1024;
    }

    public function memoryLimitMb(): ?int
    {
        return 2048;
    }

    public function volumePaths(): array
    {
        return [];
    }

    public function warnings(): array
    {
        return [
            __('Kibana needs about 1 GB of memory and several minutes for its first start, because it waits for Elasticsearch and sets up its own user.'),
        ];
    }

    public function oneTimeVariables(): array
    {
        return ['COMMYPANEL_ELASTIC_PASSWORD'];
    }

    public function environment(array $settings): array
    {
        $elastic = App::query()->where('slug', $settings['elasticsearch'] ?? '')->first();

        return [
            'ELASTICSEARCH_HOSTS' => 'http://'.($settings['elasticsearch'] ?? '').':'.(new Elasticsearch)->containerPort(),
            'ELASTICSEARCH_USERNAME' => 'kibana_system',
            'ELASTICSEARCH_PASSWORD' => $this->secret(24),
            'COMMYPANEL_ELASTIC_PASSWORD' => $elastic?->environment()['ELASTIC_PASSWORD'] ?? '',
            // Fixed keys keep sessions and saved objects readable across restarts.
            'XPACK_SECURITY_ENCRYPTIONKEY' => $this->secret(40),
            'XPACK_ENCRYPTEDSAVEDOBJECTS_ENCRYPTIONKEY' => $this->secret(40),
            'XPACK_REPORTING_ENCRYPTIONKEY' => $this->secret(40),
        ];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<DOCKER
        FROM {$this->image()}
        COPY --chown=1000:0 commypanel-start.js /usr/share/kibana/commypanel-start.js
        CMD ["sh", "-c", "exec /usr/share/kibana/node/*/bin/node /usr/share/kibana/commypanel-start.js"]

        DOCKER;

        return ['Dockerfile' => $dockerfile, 'commypanel-start.js' => self::START_SCRIPT];
    }

    /** Sets the kibana_system password (first start only), then starts Kibana as the image normally does. */
    private const START_SCRIPT = <<<'JS'
    'use strict';
    const http = require('http');
    const { spawn } = require('child_process');

    const hosts = process.env.ELASTICSEARCH_HOSTS || '';
    const admin = process.env.COMMYPANEL_ELASTIC_PASSWORD || '';
    const password = process.env.ELASTICSEARCH_PASSWORD || '';
    const deadline = Date.now() + 360000;

    function setPassword() {
        return new Promise((resolve) => {
            const url = new URL('/_security/user/kibana_system/_password', hosts);
            const body = JSON.stringify({ password });
            const request = http.request(url, {
                method: 'POST',
                auth: 'elastic:' + admin,
                headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) },
                timeout: 5000,
            }, (response) => { response.resume(); resolve(response.statusCode); });
            request.on('timeout', () => request.destroy());
            request.on('error', () => resolve(0));
            request.end(body);
        });
    }

    async function main() {
        if (admin !== '' && hosts !== '') {
            for (;;) {
                const code = await setPassword();
                if (code === 200) { console.log('The kibana_system user is ready.'); break; }
                if (code === 401 || code === 403) {
                    console.error('Elasticsearch refused the elastic password (HTTP ' + code + ').');
                    process.exit(1);
                }
                if (Date.now() > deadline) {
                    console.error('Elasticsearch did not answer in time. Is the Elasticsearch app running?');
                    process.exit(1);
                }
                await new Promise((resolve) => setTimeout(resolve, 3000));
            }
        }

        const child = spawn('/usr/local/bin/kibana-docker', [], { stdio: 'inherit' });
        ['SIGTERM', 'SIGINT'].forEach((signal) => process.on(signal, () => child.kill(signal)));
        child.on('exit', (code, signal) => process.exit(code === null ? (signal ? 1 : 0) : code));
    }

    main();

    JS;

    public function credentials(App $app): array
    {
        $elastic = App::query()->where('slug', $app->template_settings['elasticsearch'] ?? '')->first();

        if ($elastic === null) {
            return [];
        }

        return [
            $this->row(__('User'), 'elastic'),
            $this->row(__('Password'), $elastic->environment()['ELASTIC_PASSWORD'] ?? '', true),
        ];
    }
}
