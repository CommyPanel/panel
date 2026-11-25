<?php

namespace App\Deploy;

use App\Audit\AuditLogger;
use App\Databases\AppLinks;
use App\Databases\DatabaseException;
use App\FastApps\FastApps;
use App\FastApps\QuickInstall;
use App\Models\App;
use App\Models\AppLink;
use App\Models\User;

/**
 * The Redis choice of the Laravel wizard: one place that carries it out and links Redis to the app with
 * the variable names Laravel's redis settings read (REDIS_URL, REDIS_HOST, REDIS_PORT, REDIS_PASSWORD).
 *
 * Three choices:
 *  - EXISTING: one of the person's finished Redis apps is linked.
 *  - NEW: a Redis app is made for this app (a ready-made Redis, started for you) and linked. Coming back to the
 *    step reuses it.
 *  - NONE: nothing is linked.
 *
 * The choice is kept in apps.stack_options['redis'] (mode and the Redis app's slug, never a password). A Redis
 * app that was made stays when the choice changes, like a database made in the database step.
 */
class SetupAppRedis
{
    public const EXISTING = 'existing';

    public const NEW = 'new';

    public const NONE = 'none';

    public const MODES = [self::EXISTING, self::NEW, self::NONE];

    /** The prefix of the link: REDIS_URL, REDIS_HOST, REDIS_PORT and REDIS_PASSWORD. */
    public const PREFIX = 'REDIS';

    public function __construct(
        private readonly QuickInstall $quick,
        private readonly AppLinks $links,
        private readonly Deployer $deployer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The person's finished Redis apps.
     *
     * @return array<int, array{slug: string, name: string}>
     */
    public function existing(): array
    {
        return App::query()->where('template', 'redis')->whereNull('setup_step')->orderBy('name')->get(['slug', 'name'])
            ->map(fn (App $a) => ['slug' => $a->slug, 'name' => $a->name])->values()->all();
    }

    /**
     * @param  array{mode: string, app?: string|null}  $choice
     *
     * @throws DatabaseException
     */
    public function apply(App $app, array $choice, ?User $by = null): void
    {
        $mode = $choice['mode'];

        if (! in_array($mode, self::MODES, true)) {
            throw new DatabaseException(__('Choose how the app gets its Redis.'));
        }

        $target = null;

        if ($mode === self::EXISTING) {
            $target = $this->chosen($choice['app'] ?? null);
        } elseif ($mode === self::NEW) {
            $target = $this->made($app, $by);
        }

        $this->forgetPreviousLink($app, $target);

        if ($target !== null && ! $app->links()->where('target_app_id', $target->id)->exists()) {
            $this->links->linkApp($app, $target, self::PREFIX);
        }

        if ($mode === self::NEW && $target->setup_step !== null) {
            $this->finish($target, $by);
        }

        $this->remember($app, array_filter(['mode' => $mode, 'app' => $target?->slug], fn ($v) => $v !== null));

        $this->audit->record('app.redis_chosen', $by, array_filter(['app' => $app->slug, 'mode' => $mode, 'redis' => $target?->slug], fn ($v) => $v !== null), $app);
    }

    /**
     * How the step stands: the mode and the Redis app, or null for neither.
     *
     * @return array{mode: string|null, app: string|null}
     */
    public function choice(App $app): array
    {
        $state = $this->state($app);
        $link = $this->link($app);

        if ($link !== null) {
            return ['mode' => $state['mode'] ?? self::EXISTING, 'app' => $link->target->slug];
        }

        return ['mode' => ($state['mode'] ?? null) === self::NONE ? self::NONE : null, 'app' => null];
    }

    /** Whether a Redis app is linked to the app (the starting .env text then uses Redis). */
    public function linked(App $app): bool
    {
        return $this->link($app) !== null;
    }

    private function link(App $app): ?AppLink
    {
        return $app->links()->where('env_prefix', self::PREFIX)->with('target')->get()
            ->first(fn ($link) => $link->target?->template === 'redis');
    }

    /** A finished Redis app of the person's, by its slug. */
    private function chosen(?string $slug): App
    {
        $target = $slug === null ? null : App::query()->where('slug', $slug)->where('template', 'redis')->whereNull('setup_step')->first();

        if ($target === null) {
            throw new DatabaseException(__('Choose one of your Redis apps.'));
        }

        return $target;
    }

    /** The Redis app made for this app earlier (the person came back to the step), else a new one. */
    private function made(App $app, ?User $by): App
    {
        $state = $this->state($app);
        $earlier = ($state['mode'] ?? null) === self::NEW && isset($state['app'])
            ? App::query()->where('slug', (string) $state['app'])->where('template', 'redis')->first()
            : null;

        if ($earlier !== null) {
            return $earlier;
        }

        $made = $this->quick->create(FastApps::find('redis'), $app->name.' Redis', [], null);
        $this->audit->record('app.setup_started', $by, ['app' => $made->slug, 'template' => 'redis'], $made);

        return $made;
    }

    /**
     * A new Redis app is finished like a ready-made app: no domain (Redis has none), the first release starts.
     * It runs after the link exists, so the link network is there when the container starts.
     */
    private function finish(App $redis, ?User $by): void
    {
        $redis->forceFill(['setup_step' => null])->save();

        $this->audit->record('app.created', $by, ['app' => $redis->slug, 'domains' => 0], $redis);
        $this->deployer->deploy($redis->refresh(), $by);
    }

    /** The previous choice's link goes (the Redis app itself stays), unless it is the one being kept. */
    private function forgetPreviousLink(App $app, ?App $keep): void
    {
        foreach ($app->links()->where('env_prefix', self::PREFIX)->with('target')->get() as $link) {
            if ($keep === null || $link->target_app_id !== $keep->id) {
                $this->links->unlinkApp($link);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(App $app): array
    {
        $fresh = App::query()->whereKey($app->id)->value('stack_options');
        $options = is_string($fresh) ? json_decode($fresh, true) : $fresh;

        return is_array($options) && is_array($options['redis'] ?? null) ? $options['redis'] : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function remember(App $app, array $state): void
    {
        $fresh = App::query()->whereKey($app->id)->value('stack_options');
        $options = is_string($fresh) ? json_decode($fresh, true) : $fresh;
        $options = is_array($options) ? $options : [];
        $options['redis'] = $state;

        $app->forceFill(['stack_options' => $options])->save();
    }
}
