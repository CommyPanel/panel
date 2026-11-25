<?php

namespace App\FastApps;

/** One thing a person chooses to install a quick-install app. */
final readonly class Field
{
    /**
     * @param  'app'|'database'  $type  the choice is one of the person's own apps made from `$appTemplate`, or
     *                                  a PostgreSQL database that is linked to the new app under `$prefix`
     * @param  list<mixed>  $rules  Laravel validation rules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $appTemplate,
        public ?string $hint = null,
        public array $rules = [],
        public ?string $prefix = null,
    ) {}
}
