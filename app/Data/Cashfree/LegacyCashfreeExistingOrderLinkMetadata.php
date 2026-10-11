<?php

namespace App\Data\Cashfree;

final readonly class LegacyCashfreeExistingOrderLinkMetadata
{
    public function __construct(
        public string $promptId,
        public string $ingestSource,
        public string $userAgent,
    ) {}
}
