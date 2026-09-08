<?php

namespace App\Services\ChannelIngest\Data;

final class ChannelOrderTenderDraft
{
    public const TYPE_CASHFREE = 'cashfree';

    public const TYPE_WALLET = 'wallet';

    public function __construct(
        public readonly string $type,
        public readonly float $amount,
        public readonly ?string $reference = null,
    ) {}
}
