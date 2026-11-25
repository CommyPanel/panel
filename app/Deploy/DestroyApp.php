<?php

namespace App\Deploy;

use App\Backups\VolumeBackups;
use App\Databases\AppLinks;
use App\Deploy\Source\UploadStorage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\DatabaseServer;

/**
 * Removes an app's containers, images and volume data, then the app itself.
 */
class DestroyApp
{
    public function __construct(private readonly HostDriver $host, private readonly UploadStorage $uploads, private readonly AppLinks $links, private readonly VolumeBackups $volumeBackups, private readonly ReleaseProcesses $processes) {}

    /**
     * Deleting an app deletes the files of its volumes, so a locked volume
     * protects the app from deletion too.
     *
     * @throws LockedException
     */
    public static function assertUnlocked(App $app): void
    {
        if ($app->isLocked()) {
            throw new LockedException(__('App is locked and cannot be deleted. Unlock it first.'));
        }

        $locked = $app->volumes()->whereNotNull('locked_at')->orderBy('id')->pluck('path');

        if ($locked->isNotEmpty()) {
            throw new LockedException(__('Volume :path is locked, and deleting the app would delete its files. Unlock the volume first.', ['path' => $locked->first()]));
        }
    }

    /**
     * @return list<string> problems cleaning up the host; the app is deleted regardless
     *
     * @throws LockedException when the app, or one of its volumes, is locked
     */
    public function __invoke(App $app): array
    {
        self::assertUnlocked($app);

        $problems = [];
        $deployments = $app->deployments()->get();

        // The web containers, and the extra processes (workers, scheduler) of every release.
        $containers = $deployments->pluck('container_name')->filter()
            ->merge($deployments->flatMap(fn ($d) => $this->processes->plannedContainers($d)))->unique();

        foreach ($containers as $container) {
            try {
                $this->host->removeContainer($container);
            } catch (HostOperationFailed $e) {
                $problems[] = "Container {$container}: {$e->getMessage()}";
            }
        }

        // The app's containers are gone, so the database servers and apps on the other ends of its links
        // let go of the link networks, and those networks are removed.
        $this->links->removeAllFor($app);

        // After the containers: a network cannot go while something is attached.
        try {
            $this->host->removeNetwork($app->networkName());
        } catch (HostOperationFailed $e) {
            $problems[] = "Network {$app->networkName()}: {$e->getMessage()}";
        }

        foreach ($deployments->where('image_available', true)->pluck('image_tag')->filter()->unique() as $tag) {
            try {
                $this->host->removeImage($tag);
            } catch (HostOperationFailed $e) {
                $problems[] = "Image {$tag}: {$e->getMessage()}";
            }
        }

        // After the containers: nothing may still have the folders mounted.
        // The archives of its volumes go with it, like the files they hold.
        $this->volumeBackups->deleteAll($app);

        try {
            $this->host->removeVolumes($app->slug);
        } catch (HostOperationFailed $e) {
            $problems[] = "Volume data: {$e->getMessage()}";
        }

        try {
            $this->host->removeNginxSite($app->slug);
        } catch (HostOperationFailed $e) {
            $problems[] = "Web server route: {$e->getMessage()}";
        }

        try {
            $this->host->removeCertificate($app->slug);
        } catch (HostOperationFailed $e) {
            $problems[] = "Certificate: {$e->getMessage()}";
        }

        // A database server made for this app stays: its data is not ours to delete with the app. It becomes an ordinary server.
        DatabaseServer::query()->where('dedicated_app_id', $app->id)->update(['dedicated_app_id' => null]);

        $this->uploads->delete($app);
        $app->delete();

        return $problems;
    }
}
