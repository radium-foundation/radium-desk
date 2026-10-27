<?php

namespace App\Data\CustomerOrderHistory;

readonly class CustomerOrderHistoryIdentityResolution
{
    /**
     * @param  list<string>  $customerIds
     * @param  list<string>  $phones
     * @param  list<string>  $emails
     */
    public function __construct(
        public bool $resolved,
        public bool $ambiguous,
        public array $customerIds = [],
        public array $phones = [],
        public array $emails = [],
        public ?string $ambiguityReason = null,
    ) {}

    public static function unresolved(): self
    {
        return new self(resolved: false, ambiguous: false);
    }

    public static function ambiguous(string $reason): self
    {
        return new self(
            resolved: false,
            ambiguous: true,
            ambiguityReason: $reason,
        );
    }
}
