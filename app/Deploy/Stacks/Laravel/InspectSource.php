<?php

namespace App\Deploy\Stacks\Laravel;

use App\Deploy\DeploymentFailed;
use App\Deploy\Source\SourceFetcher;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\Jobs\InspectSourceJob;
use App\Models\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Looks at the code of an app that is still being set up, for the wizard: fetches it into a scratch
 * folder (a shallow clone with the app's own access, or the uploaded archive), asks LaravelInspector
 * what is in it, keeps the answer in `apps.detected` and removes the folder again.
 *
 * `detected` is null while the answer is pending. A finished answer carries `detected_at`; when the
 * code is not a Laravel app it is `{stack: null, detected_at}`, and when the code could not be read
 * it is `{error: <sentence>, detected_at}`. A Laravel app also gets `stack_hint` set when the person
 * had not chosen a type.
 */
class InspectSource
{
    public function __construct(private readonly SourceFetcher $source, private readonly LaravelInspector $inspector) {}

    /**
     * Forget the old answer and queue a new look.
     */
    public static function queue(App $app): void
    {
        $app->forceFill(['detected' => null])->save();
        InspectSourceJob::dispatch($app->id);
    }

    public function __invoke(App $app): void
    {
        // Only a draft is looked at, and a ready-made app has no code of its own.
        if ($app->setup_step === null || $app->source_type === SourceType::Template) {
            return;
        }

        $dir = rtrim((string) config('commypanel.data_path'), '/')."/builds/inspect-{$app->slug}";
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir, 0755);

        try {
            $this->source->fetch($app, $dir);

            if (! LaravelProject::matches($dir)) {
                $this->store($app, ['stack' => null]);

                return;
            }

            $this->store($app, ['stack' => Stack::Laravel->value, ...$this->inspector->inspect($dir)]);

            if ($app->stack_hint === null) {
                $app->forceFill(['stack_hint' => Stack::Laravel])->save();
            }
        } catch (DeploymentFailed $e) {
            // The fetchers word these for people (a repository that cannot be read, a missing upload).
            $this->store($app, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::warning('Looking at the source of an app failed.', ['app' => $app->slug, 'error' => $e->getMessage()]);
            $this->store($app, ['error' => 'The code could not be inspected. Check that the repository or archive is a complete Laravel project.']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function store(App $app, array $result): void
    {
        $app->forceFill(['detected' => [...$result, 'detected_at' => now()->toIso8601String()]])->save();
    }
}
