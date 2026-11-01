<?php

namespace App\Jobs;

use App\Updates\UpdateChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks GitHub whether a newer release exists, in the background, so signing
 * in is never slowed down by it.
 */
class CheckForUpdates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function handle(UpdateChecker $updates): void
    {
        // Due-ness is checked again: several sign-ins can queue it before the first one runs.
        if ($updates->shouldCheck()) {
            $updates->check();
        }
    }
}
