<?php

use App\Jobs\RunDeployment;

it('does not hand a running deployment to a second worker', function () {
    $job = new RunDeployment(1);

    // A job still running after retry_after is picked up again; with one try that marks a healthy
    // deployment as interrupted. The worker's own timeout (3700, see the queue unit) is the limit.
    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout + 100);
});
