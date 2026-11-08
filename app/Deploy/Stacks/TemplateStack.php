<?php

namespace App\Deploy\Stacks;

use App\Deploy\Source\TemplateSourceFetcher;
use App\Enums\Stack;
use App\FastApps\FastApps;

/** A quick-install app: the build context was written by the panel, and says which template it is. */
class TemplateStack implements StackDefinition
{
    public function stack(): Stack
    {
        return Stack::Wordpress;
    }

    public function matches(string $path): bool
    {
        return is_file($path.'/'.TemplateSourceFetcher::MARKER);
    }

    public function plan(string $path, int $port): BuildPlan
    {
        $id = trim((string) @file_get_contents($path.'/'.TemplateSourceFetcher::MARKER));
        $template = FastApps::find($id);

        if ($template === null) {
            throw new DetectionFailed(__('This quick-install app is not known to this version of CommyPanel.'));
        }

        // The Dockerfile is the one the template wrote; the build uses it as it is.
        return new BuildPlan($template->stack(), null, null, [__('Quick install: :name.', ['name' => $template->name()])]);
    }
}
