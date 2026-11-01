<?php

namespace App\Deploy;

use App\Deploy\Source\UploadStorage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;

/**
 * Removes an app's containers, images and volume data, then the app itself.
 */
class DestroyApp
{
    public function __construct(private readonly HostDriver $host, private readonly UploadStorage $uploads) {}

    /**
     * @return list<string> problems cleaning up the host; the app is deleted regardless
     */
    public function __invoke(App $app): array
    {
        $problems = [];
        $deployments = $app->deployments()->get();

        foreach ($deployments->pluck('container_name')->filter()->unique() as $container) {
            try {
                $this->host->removeContainer($container);
            } catch (HostOperationFailed $e) {
                $problems[] = "Container {$container}: {$e->getMessage()}";
            }
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
