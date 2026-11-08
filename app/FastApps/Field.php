<?php

namespace App\FastApps;

/** One thing a person fills in to install a quick-install app (the site title, the first user...). */
final readonly class Field
{
    /**
     * @param  'text'|'email'|'password'  $type
     * @param  list<mixed>  $rules  Laravel validation rules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public ?string $hint = null,
        public array $rules = ['required', 'string'],
        public ?string $default = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'hint' => $this->hint, 'default' => $this->default];
    }
}
