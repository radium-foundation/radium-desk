<?php

namespace App\Services\ReturnExchange;

use App\Models\ReferenceSequence;
use App\Services\OperationalReferenceSequenceService;

final class OnlineReturnRefundAllocationReferenceService
{
    private const FLOOR = 1000;

    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function generate(): string
    {
        return $this->sequences->allocate(
            ReferenceSequence::ONLINE_RETURN_REFUND_ALLOCATION_OPERATIONAL,
            self::FLOOR,
            'ORA-',
        );
    }
}
