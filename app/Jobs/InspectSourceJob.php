<?php

namespace App\Jobs;

use App\Deploy\Stacks\Laravel\InspectSource;
use App\Models\App;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Looks at the code of an app in the setup wizard (see InspectSource). Queue it with InspectSource::queue().
 */
class InspectSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $appId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("inspect-source-{$this->appId}"))->releaseAfter(10)->expireAfter(400)];
    }

    public function handle(InspectSource $inspect): void
    {
        $app = App::query()->find($this->appId);

        if ($app !== null) {
            $inspect($app);
        }
    }

    /**
     * The worker died or timed out: the wizard must not wait for an answer that will never come.
     */
    public function failed(?Throwable $exception): void
    {
        $app = App::query()->find($this->appId);

        if ($app !== null && $app->detected === null) {
            $app->forceFill(['detected' => ['error' => 'Looking at the code took too long or stopped.', 'detected_at' => now()->toIso8601String()]])->save();
        }
    }
}
