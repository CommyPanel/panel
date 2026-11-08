<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Safe\RolloutService;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The settings of watching new releases and of gradual rollouts. Watching is on by default;
 * a gradual rollout is off, and turning it on needs the person to say they understand what
 * it asks of the app (the dialog on the page makes them read it).
 */
class AppSafeDeployController extends Controller
{
    public function update(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'watch_enabled' => ['required', 'boolean'],
            'watch_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'rollout_enabled' => ['required', 'boolean'],
            'rollout_steps' => ['required', 'array', 'min:1', 'max:4'],
            'rollout_steps.*' => ['required', 'integer', 'min:1', 'max:99', 'distinct'],
            'rollout_hold_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'rollout_auto' => ['required', 'boolean'],
            'understood' => ['boolean'],
        ], ['rollout_steps.*.distinct' => 'Each step needs a different share.']);

        // Not a validation rule, because a rule is skipped when the field was not sent at all.
        if ($data['rollout_enabled'] && ! $app->rollout_enabled && ! $request->boolean('understood')) {
            throw ValidationException::withMessages(['understood' => __('Read what a gradual rollout asks of your app, and confirm that you understand it.')]);
        }

        $steps = array_values(array_map(intval(...), $data['rollout_steps']));
        sort($steps);

        $before = $this->summary($app);
        $app->forceFill([
            'watch_enabled' => (bool) $data['watch_enabled'],
            'watch_minutes' => (int) $data['watch_minutes'],
            'rollout_enabled' => (bool) $data['rollout_enabled'],
            'rollout_steps' => $steps,
            'rollout_hold_minutes' => (int) $data['rollout_hold_minutes'],
            'rollout_auto' => (bool) $data['rollout_auto'],
        ])->save();

        $audit->record('app.safe_deploys_changed', $request->user(), ['app' => $app->slug, 'from' => $before, 'to' => $this->summary($app)], $app);

        return back()->with('status', __('Saved. The new settings apply from the next deployment.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(App $app): array
    {
        return [
            'watch' => $app->watch_enabled ? "{$app->watch_minutes} min" : 'off',
            'rollout' => $app->rollout_enabled ? implode('%, ', RolloutService::stepsFor($app)).'%, '.$app->rollout_hold_minutes.' min, '.($app->rollout_auto ? 'automatic' : 'manual') : 'off',
        ];
    }
}
