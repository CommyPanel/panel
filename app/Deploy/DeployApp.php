<?php

namespace App\Deploy;

use App\Audit\AuditLogger;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Stacks\DetectionFailed;
use App\Deploy\Stacks\StackDetector;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use App\Enums\Stack;
use App\Host\Data\ContainerSpec;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\TaskSpec;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Tls\TlsPlanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one deployment: fetch, build, run the release command, start the new
 * container next to the old one, health-check it, move traffic, then retire
 * the old container. If any step fails the previous version keeps serving.
 */
class DeployApp
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly SourceFetcher $source,
        private readonly StackDetector $detector,
        private readonly HealthChecker $health,
        private readonly TrafficSwitcher $traffic,
        private readonly PortAllocator $ports,
        private readonly AuditLogger $audit,
        private readonly TlsPlanner $tls,
        private readonly AppVolumes $volumes,
    ) {}

    public function __invoke(Deployment $deployment): void
    {
        $app = $deployment->app;
        $log = new DeployLogger($deployment);
        $workDir = null;
        $started = false;

        try {
            $deployment->forceFill(['status' => DeploymentStatus::Building, 'started_at' => now()])->save();

            if ($deployment->rolled_back_from_id === null) {
                $workDir = $this->workDir($app, $deployment);
                $this->build($app, $deployment, $workDir, $log);

                if ($deployment->release_command !== null) {
                    $this->release($app, $deployment, $log);
                }
            } else {
                $log->step("Rolling back to release {$deployment->image_tag}.");
            }

            $deployment->forceFill(['status' => DeploymentStatus::Starting])->save();
            $started = $this->start($app, $deployment, $log);
            $this->checkHealth($app, $deployment, $log);

            $previous = $app->activeDeployment;
            $this->stage($deployment, DeploymentStage::Switch);
            $log->step('Switching traffic to the new version.');
            $this->traffic->switchTo($deployment);
            $app->forceFill(['active_deployment_id' => $deployment->id, 'stopped_at' => null])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Active, 'stage' => DeploymentStage::Done, 'finished_at' => now()])->save();

            $this->tls->ensure($app);
            $this->retire($previous, $log);
            $this->pruneImages($app, $log);

            $log->line('Deployment finished.');
            $this->audit->record('deploy.succeeded', null, ['app' => $app->slug, 'deployment' => $deployment->number]);
        } catch (Throwable $e) {
            $this->fail($deployment, $log, $e, $started);
        } finally {
            $log->flush();

            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
    }

    private function stage(Deployment $deployment, DeploymentStage $stage): void
    {
        $deployment->forceFill(['stage' => $stage])->save();
    }

    private function workDir(App $app, Deployment $deployment): string
    {
        $dir = rtrim((string) config('commypanel.data_path'), '/')."/builds/{$app->slug}-{$deployment->number}";

        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir, 0755);

        return $dir;
    }

    private function build(App $app, Deployment $deployment, string $workDir, DeployLogger $log): void
    {
        $this->stage($deployment, DeploymentStage::Fetch);
        $log->step("Fetching {$app->git_url} ({$app->git_branch}).");
        $sha = $this->source->fetch($app, $workDir);
        $deployment->forceFill(['commit_sha' => $sha, 'commit_message' => $this->source->lastCommitMessage()])->save();
        $log->step("Commit {$sha}.");

        try {
            $plan = $this->detector->plan($workDir, $app->container_port);
        } catch (DetectionFailed $e) {
            throw new DeploymentFailed($e->getMessage(), 0, $e);
        }

        foreach ($plan->notes as $note) {
            $log->line($note);
        }

        $dockerfile = 'Dockerfile';

        if ($plan->dockerfile !== null) {
            $dockerfile = 'Dockerfile.commypanel';
            File::put("{$workDir}/{$dockerfile}", $plan->dockerfile);

            if ($plan->dockerignore !== null && ! File::exists("{$workDir}/.dockerignore")) {
                File::put("{$workDir}/.dockerignore", $plan->dockerignore);
            }
        }

        if ($app->stack_hint !== null && $plan->stack !== $app->stack_hint && $plan->stack !== Stack::Dockerfile) {
            throw new DeploymentFailed("You chose {$app->stack_hint->label()}, but this project looks like {$plan->stack->label()}. Change the app type or leave it blank to detect it.");
        }

        $app->forceFill(['stack' => $plan->stack])->save();

        $tag = "commypanel/{$app->slug}:{$deployment->number}";
        $deployment->forceFill(['image_tag' => $tag])->save();
        $this->stage($deployment, DeploymentStage::Build);
        $log->step("Building image {$tag}.");

        $this->host->buildImage(new ImageBuildSpec($tag, $workDir, $dockerfile), $log->line(...));
    }

    private function start(App $app, Deployment $deployment, DeployLogger $log): bool
    {
        $port = $this->ports->allocate($deployment);
        $name = "{$app->slug}-{$deployment->number}";
        $deployment->forceFill(['container_name' => $name])->save();

        $this->stage($deployment, DeploymentStage::Start);
        $log->step("Starting container {$name} on port {$port}.");

        $this->host->runContainer(new ContainerSpec(
            name: $name,
            image: (string) $deployment->image_tag,
            hostPort: $port,
            containerPort: $app->container_port,
            env: $this->environment($app),
            memoryLimitMb: $app->memory_limit_mb,
            cpuLimit: $app->cpu_limit === null ? null : (float) $app->cpu_limit,
            volumes: $this->volumes->mounts($app),
        ));

        return true;
    }

    /**
     * Run the release command once in a container from the new image, with the
     * app's environment, before the new version starts. The old version keeps
     * serving while it runs, so the command must leave the data usable by it.
     */
    private function release(App $app, Deployment $deployment, DeployLogger $log): void
    {
        try {
            $command = CommandLine::split((string) $deployment->release_command);
        } catch (InvalidArgumentException $e) {
            throw new DeploymentFailed("The release command is not valid: {$e->getMessage()}", 0, $e);
        }

        $timeout = min(3600, max(1, (int) config('commypanel.deploy.release_timeout')));
        $this->stage($deployment, DeploymentStage::Release);
        $log->step("Running the release command: {$deployment->release_command}");

        $code = $this->host->runTask(new TaskSpec(
            name: "{$app->slug}-{$deployment->number}-release",
            image: (string) $deployment->image_tag,
            command: $command,
            env: $this->environment($app),
            memoryLimitMb: $app->memory_limit_mb,
            cpuLimit: $app->cpu_limit === null ? null : (float) $app->cpu_limit,
            volumes: $this->volumes->mounts($app),
            timeoutSeconds: $timeout,
        ), $log->line(...));

        if ($code !== 0) {
            throw new DeploymentFailed("The release command failed with exit code {$code}. The new version was not started.");
        }

        $log->line('The release command finished.');
    }

    /**
     * @return array<string, string>
     */
    private function environment(App $app): array
    {
        return ['PORT' => (string) $app->container_port, ...$app->environment()];
    }

    private function checkHealth(App $app, Deployment $deployment, DeployLogger $log): void
    {
        $timeout = (int) config('commypanel.deploy.health_timeout');
        $this->stage($deployment, DeploymentStage::Health);
        $log->step("Waiting up to {$timeout}s for {$app->health_path} to answer.");

        if ($this->health->waitUntilHealthy((int) $deployment->host_port, $app->health_path, $timeout)) {
            $log->line('The new version is healthy.');

            return;
        }

        try {
            $log->line('--- container output ---');
            $log->line(rtrim($this->host->containerLogs((string) $deployment->container_name, 50)));
            $log->line('------------------------');
        } catch (HostOperationFailed) {
            // Output is a nicety; the failure below is what matters.
        }

        throw new DeploymentFailed("The app did not answer on port {$app->container_port} within {$timeout} seconds. Check that it listens on the PORT environment variable.");
    }

    private function retire(?Deployment $previous, DeployLogger $log): void
    {
        if ($previous === null || $previous->container_name === null) {
            return;
        }

        $drain = (int) config('commypanel.deploy.drain_seconds');
        $log->step("Stopping the previous version ({$previous->container_name}) in {$drain}s.");
        Sleep::for($drain)->seconds();

        try {
            $this->host->stopContainer($previous->container_name);
            $this->host->removeContainer($previous->container_name);
        } catch (HostOperationFailed $e) {
            // The new version is already live; do not fail the deploy over cleanup.
            Log::warning('Could not retire the previous container.', ['container' => $previous->container_name, 'error' => $e->getMessage()]);
            $log->line('Warning: could not stop the previous container: '.$e->getMessage());
        }

        $previous->forceFill(['status' => DeploymentStatus::Superseded])->save();
    }

    /**
     * Keep the newest releases' images for rollback and remove the rest.
     */
    private function pruneImages(App $app, DeployLogger $log): void
    {
        $keep = max(1, (int) config('commypanel.deploy.keep_images'));

        /** @var list<string> $tags newest release first */
        $tags = $app->deployments()
            ->where('image_available', true)
            ->whereNotNull('image_tag')
            ->selectRaw('image_tag, max(number) as newest')
            ->groupBy('image_tag')
            ->orderByDesc('newest')
            ->pluck('image_tag')
            ->all();

        foreach (array_slice($tags, $keep) as $tag) {
            try {
                $this->host->removeImage($tag);
                $app->deployments()->where('image_tag', $tag)->update(['image_available' => false]);
                $log->line("Removed old image {$tag}.");
            } catch (HostOperationFailed $e) {
                Log::warning('Could not remove an old image.', ['tag' => $tag, 'error' => $e->getMessage()]);
            }
        }
    }

    private function fail(Deployment $deployment, DeployLogger $log, Throwable $e, bool $containerStarted): void
    {
        $message = match (true) {
            $e instanceof DeploymentFailed, $e instanceof DetectionFailed => $e->getMessage(),
            $e instanceof DockerUnavailable => 'Docker is not available on this server.',
            $e instanceof HostOperationFailed => $e->getMessage(),
            default => 'An unexpected error occurred. See the panel log for details.',
        };

        if (! ($e instanceof DeploymentFailed || $e instanceof HostOperationFailed || $e instanceof DetectionFailed)) {
            report($e);
        }

        $log->line("Deployment failed: {$message}");

        if ($containerStarted && $deployment->container_name !== null) {
            try {
                $this->host->removeContainer($deployment->container_name);
            } catch (Throwable $cleanup) {
                Log::warning('Could not remove the failed container.', ['error' => $cleanup->getMessage()]);
            }
        }

        $log->flush();
        $deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'error' => $message,
            'finished_at' => now(),
        ])->save();

        $this->audit->record('deploy.failed', null, [
            'app' => $deployment->app->slug,
            'deployment' => $deployment->number,
            'reason' => $message,
        ]);
    }
}
