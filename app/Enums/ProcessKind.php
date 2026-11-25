<?php

namespace App\Enums;

use App\Models\AppProcess;

/**
 * What an extra process of an app is. The web process is the app itself and is not one of these.
 * Each kind knows its usual command, whether a new release may run beside the old one while it
 * starts (`overlap`) or must wait until the old one has stopped (`stop_first`), and how long the
 * old one gets to finish its work when it is stopped.
 */
enum ProcessKind: string
{
    case Scheduler = 'scheduler';
    case Queue = 'queue';
    case Horizon = 'horizon';
    case Pulse = 'pulse';
    case Ssr = 'ssr';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Scheduler => __('Scheduler'),
            self::Queue => __('Queue worker'),
            self::Horizon => __('Horizon'),
            self::Pulse => __('Pulse'),
            self::Ssr => __('Inertia SSR'),
            self::Custom => __('Custom process'),
        };
    }

    public function defaultCommand(): ?string
    {
        return match ($this) {
            self::Scheduler => 'php artisan schedule:work',
            self::Queue => 'php artisan queue:work --tries=3 --timeout=90',
            self::Horizon => 'php artisan horizon',
            self::Pulse => 'php artisan pulse:check',
            self::Ssr => 'php artisan inertia:start-ssr',
            self::Custom => null,
        };
    }

    /** `overlap` or `stop_first`. */
    public function defaultOverlap(): string
    {
        return match ($this) {
            self::Queue, self::Custom => AppProcess::OVERLAP,
            default => AppProcess::STOP_FIRST,
        };
    }

    public function defaultGraceSeconds(): int
    {
        return $this === self::Horizon ? 60 : 30;
    }

    /** Whether more than one copy may run (workers); the others are singletons. */
    public function mayReplicate(): bool
    {
        return in_array($this, [self::Queue, self::Custom], true);
    }

    /** The name a new process of this kind gets (a custom one is named by the person). */
    public function defaultName(): string
    {
        return $this->value === 'custom' ? 'worker' : $this->value;
    }
}
