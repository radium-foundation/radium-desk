<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360Action
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $url,
        public bool $enabled,
        public bool $soon,
    ) {}
}
