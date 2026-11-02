<?php

namespace App\Deploy;

use App\Databases\AppLinks;
use App\Deploy\Source\UploadStorage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;

/**
 * Removes an app's containers, images and volume data, then the app itself.
 */
class DestroyApp
{
    public function __construct(private readonly HostDriver $host, private readonly UploadStorage $uploads, private readonly AppLinks $links) {}

    /**
     * Deleting an app deletes the files of its volumes, so a locked volume
     * protects the app from deletion too.
     *
     * @throws LockedException
     */
    public static function assertUnlocked(App $app): void
    {
        if ($app->isLocked()) {
            throw new LockedException('App is locked and cannot be deleted. Unlock it first.');
        }

        $locked = $app->volumes()->whereNotNull('locked_at')->orderBy('id')->pluck('path');

        if ($locked->isNotEmpty()) {
            throw new LockedException('Volume '.$locked->first().' is locked, and deleting the app would delete its files. Unlock the volume first.');
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

        foreach ($deployments->pluck('container_name')->filter()->unique() as $container) {
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

        $this->uploads->delete($app);
        $app->delete();

        return $problems;
    }
}
