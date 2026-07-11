<?php

namespace App\Data\Eligibility;

readonly class EligibilityResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(allowed: true);
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }
}
