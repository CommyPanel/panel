<?php

namespace App\Alerts;

/**
 * One message, before it is shaped for a kind of target. The text is plain English: it is
 * written by jobs and sent to chats, not shown in the panel, so it is not translated.
 */
final readonly class AlertMessage
{
    /**
     * @param  list<string>  $lines
     * @param  string|null  $app  the slug of the app it is about, so the app's switch can mute it
     * @param  array<string, mixed>  $data  extra fields for the generic JSON format
     * @param  AlertEvent|null  $event  null only for the test message
     */
    public function __construct(
        public ?AlertEvent $event,
        public string $title,
        public array $lines,
        public string $link,
        public ?string $app = null,
        public array $data = [],
    ) {}

    public function eventKey(): string
    {
        return $this->event === null ? 'test' : $this->event->value;
    }
}
