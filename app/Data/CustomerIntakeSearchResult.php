<?php

namespace App\Data;

use App\Enums\CustomerIdentityType;

class CustomerIntakeSearchResult
{
    /**
     * @param  list<array{
     *     id: int,
     *     order_id: string,
     *     customer_phone: ?string,
     *     serial_number: ?string,
     *     product_name: ?string,
     *     identity_type: string,
     *     legacy_source: ?string,
     * }>  $matches
     */
    public function __construct(
        public readonly CustomerIdentityType $classification,
        public readonly array $matches,
        public readonly ?string $legacySource = null,
        public readonly ?LegacyOrderPreview $legacyPreview = null,
        public readonly bool $requiresConfirmation = false,
    ) {}
}
