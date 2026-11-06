<?php

namespace App\Presenters;

use App\Deploy\Safe\RolloutService;
use App\Deploy\Safe\WatchService;
use App\Host\Data\TrafficStat;
use App\Models\Deployment;
use App\Models\Rollout;
use App\Traffic\Verdict;

/**
 * What the deployment page shows about a gradual rollout or a watch: where it stands and how
 * the new release compares with the old one right now.
 */
class SafeDeployPresenter
{
    public function __construct(
        private readonly RolloutService $rollouts,
        private readonly WatchService $watch,
    ) {}

    /**
     * @return array{rollout: array<string, mixed>|null, traffic: array<string, mixed>|null}
     */
    public function for(Deployment $deployment): array
    {
        $rollout = Rollout::query()->where('deployment_id', $deployment->id)->latest('id')->first();

        if ($rollout !== null) {
            return [
                'rollout' => $this->rollout($rollout),
                'traffic' => $rollout->isActive() ? $this->traffic($this->rollouts->look($rollout)) : null,
            ];
        }

        if ($deployment->watch_state === 'watching') {
            $previous = $deployment->watch_previous_id === null ? null : Deployment::query()->find($deployment->watch_previous_id);

            return ['rollout' => null, 'traffic' => [...$this->traffic($this->watch->look($deployment->app, $deployment, $previous)), 'previous_number' => $previous?->number]];
        }

        return ['rollout' => null, 'traffic' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollout(Rollout $rollout): array
    {
        return [
            'state' => $rollout->state,
            'percent' => $rollout->percent,
            'step' => $rollout->step_index + 1,
            'steps' => $rollout->steps,
            'hold_minutes' => $rollout->hold_minutes,
            'auto' => $rollout->auto,
            'step_started_at' => $rollout->step_started_at->toIso8601String(),
            'step_ends_at' => $rollout->step_started_at->copy()->addMinutes($rollout->hold_minutes)->toIso8601String(),
            'note' => $rollout->note,
            'previous_number' => $rollout->previous?->number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function traffic(Verdict $verdict): array
    {
        return [
            'new' => $this->stat($verdict->new),
            'reference' => $this->stat($verdict->reference),
            'verdict' => ['state' => $verdict->state, 'reason' => $verdict->reason],
        ];
    }

    /**
     * @return array{requests: int, errors: int, error_percent: float, p95_ms: float}|null
     */
    private function stat(?TrafficStat $stat): ?array
    {
        return $stat === null ? null : [
            'requests' => $stat->requests,
            'errors' => $stat->errors,
            'error_percent' => round($stat->errorRate() * 100, 1),
            'p95_ms' => $stat->p95,
        ];
    }
}
