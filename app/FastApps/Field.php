<?php

namespace App\FastApps;

/** One thing a person chooses to install a quick-install app. */
final readonly class Field
{
    /**
     * @param  'app'  $type  the choice is one of the person's own apps made from `$appTemplate`
     * @param  list<mixed>  $rules  Laravel validation rules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $appTemplate,
        public ?string $hint = null,
        public array $rules = [],
    ) {}
}
