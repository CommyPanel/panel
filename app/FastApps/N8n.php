<?php

namespace App\FastApps;

use App\Models\App;

/**
 * n8n, workflow automation. Its credentials and workflows live in a volume, encrypted with a
 * generated key that stays in the app's variables (without it the saved credentials cannot be read
 * again). It sits behind the panel's nginx, so it is told to trust one proxy. Its login cookie is
 * secure, so it needs HTTPS: a domain and its certificate. It needs real memory (2 GB limit, 1.5 GB heap).
 */
class N8n extends QuickApp
{
    public function id(): string
    {
        return 'n8n';
    }

    public function name(): string
    {
        return 'n8n';
    }

    public function description(): string
    {
        return __('Connect your apps and services with automated workflows, built in the browser.');
    }

    protected function image(): string
    {
        return 'docker.n8n.io/n8nio/n8n:3.2.2';
    }

    public function containerPort(): int
    {
        return 5678;
    }

    public function healthPath(): string
    {
        return '/healthz';
    }

    public function healthTimeout(): ?int
    {
        // The first start makes its database.
        return 180;
    }

    public function minimumMemoryMb(): ?int
    {
        // its Node heap is 1.5 GB: below this the system kills it for lack of memory.
        return 2048;
    }

    public function memoryLimitMb(): ?int
    {
        // Its first start runs a long list of database migrations; with the default 512 MB the Node
        // heap stops at about 250 MB and it crashes in a loop.
        return 2048;
    }

    public function volumePaths(): array
    {
        return ['/home/node/.n8n'];
    }

    public function warnings(): array
    {
        return [
            __('n8n needs about 2 GB of memory for itself. On a smaller server other apps may be stopped by the system.'),
            __('n8n only lets you sign in over HTTPS, so give it a domain and wait for its certificate. After adding the domain, add the variable WEBHOOK_URL (https:// and the domain, with a closing slash) on the Variables tab and apply, so webhooks show the right address.'),
        ];
    }

    public function environment(array $settings): array
    {
        return ['N8N_ENCRYPTION_KEY' => $this->secret(40)];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV NODE_OPTIONS=--max-old-space-size=1536 N8N_PORT=5678 N8N_PROXY_HOPS=1 N8N_DIAGNOSTICS_ENABLED=false N8N_VERSION_NOTIFICATIONS_ENABLED=false N8N_TEMPLATES_ENABLED=false

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('Encryption key'), $this->variable($app, 'N8N_ENCRYPTION_KEY'), true),
        ];
    }
}
