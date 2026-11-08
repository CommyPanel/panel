<?php

namespace App\Deploy\Source;

use App\Enums\SourceType;
use App\Models\App;

class CompositeSourceFetcher implements SourceFetcher
{
    public function __construct(
        private readonly GitSourceFetcher $git,
        private readonly UploadSourceFetcher $upload,
        private readonly TemplateSourceFetcher $template,
    ) {}

    private ?SourceFetcher $last = null;

    public function lastCommitMessage(): ?string
    {
        return $this->last?->lastCommitMessage();
    }

    public function fetch(App $app, string $destination): string
    {
        $this->last = match ($app->source_type) {
            SourceType::Upload => $this->upload,
            SourceType::Template => $this->template,
            default => $this->git,
        };

        return $this->last->fetch($app, $destination);
    }
}
