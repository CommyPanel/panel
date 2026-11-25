<?php

namespace App\FastApps;

use App\Auth\Permissions;
use App\Enums\DatabaseEngine;
use App\Models\App;
use App\Models\ManagedDatabase;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Umami, privacy-friendly web analytics. It keeps nothing itself: its data lives in one of the person's
 * PostgreSQL databases, which is linked to it as DATABASE_URL when it is made. Umami ships with the
 * account admin / umami, so a start script replaces that password by a generated one on the first
 * start (only while it is still the shipped one: a password the person set is never touched).
 */
class Umami extends QuickApp
{
    public const PREFIX = 'DATABASE';

    public function id(): string
    {
        return 'umami';
    }

    public function name(): string
    {
        return 'Umami';
    }

    public function description(): string
    {
        return __('Simple, privacy-friendly website analytics, without cookies. Keeps its data in one of your PostgreSQL databases.');
    }

    protected function image(): string
    {
        return 'ghcr.io/umami-software/umami:3.5.0';
    }

    public function fields(): array
    {
        return [new Field(
            key: 'database',
            label: __('PostgreSQL database'),
            type: 'database',
            appTemplate: '',
            hint: __('Umami stores its data here over a private network. Make a PostgreSQL server and a database first (Databases), and use an empty one. You need permission to link apps.'),
            rules: ['required', 'integer', $this->databaseRule(...)],
            prefix: self::PREFIX,
        )];
    }

    /** Only a PostgreSQL database that is ready, and only for a person who may link apps to databases. */
    private function databaseRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Gate::allows(Permissions::APPS_LINKS)) {
            $fail(__('You need permission to link apps to databases.'));

            return;
        }

        $database = ManagedDatabase::query()->with('server')->find((int) $value);

        if ($database === null || $database->server->engine !== DatabaseEngine::Postgres || $database->restore_status !== null) {
            $fail(__('Choose one of your PostgreSQL databases.'));
        }
    }

    public function containerPort(): int
    {
        return 3000;
    }

    public function healthPath(): string
    {
        return '/api/heartbeat';
    }

    public function healthTimeout(): ?int
    {
        // The first start builds its tables.
        return 180;
    }

    public function volumePaths(): array
    {
        return [];
    }

    public function warnings(): array
    {
        return [
            __('Use an empty database for Umami: on its first start it creates its own tables in it.'),
        ];
    }

    public function environment(array $settings): array
    {
        $password = $this->secret(20);

        return [
            'APP_SECRET' => $this->secret(40),
            'COMMYPANEL_ADMIN_PASSWORD' => $password,
            // Umami checks passwords with bcrypt; PHP's $2y$ is the same algorithm as the $2b$ it expects.
            'COMMYPANEL_ADMIN_HASH' => Str::replaceFirst('$2y$', '$2b$', password_hash($password, PASSWORD_BCRYPT)),
        ];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<DOCKER
        FROM {$this->image()}
        ENV DISABLE_TELEMETRY=1
        COPY --chown=nextjs:nodejs commypanel-admin.mjs commypanel-start.sh /app/
        CMD ["sh", "/app/commypanel-start.sh"]

        DOCKER;

        return ['Dockerfile' => $dockerfile, 'commypanel-admin.mjs' => self::ADMIN_SCRIPT, 'commypanel-start.sh' => self::START_SCRIPT];
    }

    /** Runs Umami's own database check (it makes the tables), sets the admin password, then starts it as the image does. */
    private const START_SCRIPT = <<<'SH'
    #!/bin/sh
    set -e
    cd /app
    export PATH="/app/node_modules/.bin:$PATH"
    node scripts/check-db.js
    node commypanel-admin.mjs
    exec sh scripts/start-docker.sh
    SH;

    private const ADMIN_SCRIPT = <<<'JS'
    import { PrismaPg } from '@prisma/adapter-pg';
    import { PrismaClient } from './generated/prisma/client.js';

    // Umami ships with the account admin / umami. Replace that password by the generated one, but only
    // while it is still the shipped one: a password the person has set since is never touched.
    const shipped = '$2b$10$BUli0c.muyCW1ErNJc3jL.vFRFtFJWrT8/GcR4A.sUdCznaXiqFXa';
    const hash = process.env.COMMYPANEL_ADMIN_HASH || '';

    if (/^\$2[ab]\$\d\d\$[./A-Za-z0-9]{53}$/.test(hash) && process.env.DATABASE_URL) {
        const url = new URL(process.env.DATABASE_URL);
        const prisma = new PrismaClient({
            adapter: new PrismaPg({ connectionString: url.toString() }, { schema: url.searchParams.get('schema') }),
        });

        try {
            const changed = await prisma.$executeRaw`UPDATE "user" SET password = ${hash} WHERE username = 'admin' AND password = ${shipped}`;
            console.log(changed > 0 ? 'Set the generated admin password.' : 'The admin password is already changed.');
        } finally {
            await prisma.$disconnect();
        }
    }
    JS;

    public function credentials(App $app): array
    {
        return [
            $this->row(__('User'), 'admin'),
            $this->row(__('Password'), $this->variable($app, 'COMMYPANEL_ADMIN_PASSWORD'), true),
        ];
    }
}
