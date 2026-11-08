<?php

namespace App\Deploy\Source;

use App\Deploy\DeploymentFailed;
use App\FastApps\FastApps;
use App\Models\App;
use Illuminate\Support\Facades\File;

/**
 * The "source" of a quick-install app: a small build context the panel writes itself (a Dockerfile on
 * top of the official image and a few helper files). The marker file tells the stack detector.
 */
class TemplateSourceFetcher implements SourceFetcher
{
    public const MARKER = '.commypanel-template';

    private ?string $release = null;

    public function lastCommitMessage(): ?string
    {
        return $this->release;
    }

    public function fetch(App $app, string $destination): string
    {
        $template = FastApps::find($app->template);

        if ($template === null) {
            throw new DeploymentFailed(__('This quick-install app is not known to this version of CommyPanel.'));
        }

        foreach ($template->files($app) as $name => $contents) {
            // The names are fixed in the template; refuse anything that could leave the folder all the same.
            if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
                throw new DeploymentFailed(__('The template for this app is not valid.'));
            }

            File::put("{$destination}/{$name}", $contents);
        }

        File::put($destination.'/'.self::MARKER, $template->id()."\n");
        $this->release = $template->release();

        return 'template-'.$template->id().'-'.substr(hash('sha256', json_encode($template->files($app)) ?: ''), 0, 8);
    }
}
