<?php

namespace App\Updates;

use Carbon\CarbonInterface;

/**
 * What the panel knows about newer releases, from its last answer from GitHub.
 */
final readonly class UpdateStatus
{
    /**
     * @param  list<array{version: string, name: string, url: string, published_at: string|null, notes_html: string}>  $newer  releases newer than the running version, newest first
     * @param  array{version: string, name: string, url: string, published_at: string|null, notes_html: string}|null  $installed  the release the panel is running, when it is among the cached ones
     */
    public function __construct(
        public string $current,
        /** False for a development checkout, which has no version to compare. */
        public bool $supported,
        public bool $enabled,
        /** Switched off in the server configuration, so not even a manual check is made. */
        public bool $forcedOff,
        public ?string $latest,
        public bool $available,
        public array $newer,
        public ?array $installed,
        public ?CarbonInterface $checkedAt,
        public ?string $error,
        public string $releasesUrl,
    ) {}

    /**
     * The little the top bar needs, or null when there is nothing to show.
     *
     * @return array{current: string, latest: string}|null
     */
    public function summary(): ?array
    {
        return $this->available && $this->enabled && $this->supported && $this->latest !== null
            ? ['current' => $this->current, 'latest' => $this->latest]
            : null;
    }

    /**
     * Everything the updates page shows.
     *
     * @return array<string, mixed>
     */
    public function page(): array
    {
        return [
            'current' => $this->current,
            'supported' => $this->supported,
            'enabled' => $this->enabled,
            'forced_off' => $this->forcedOff,
            'latest' => $this->latest,
            'available' => $this->available,
            'newer' => $this->newer,
            'installed' => $this->installed,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'error' => $this->error,
            'releases_url' => $this->releasesUrl,
        ];
    }
}
