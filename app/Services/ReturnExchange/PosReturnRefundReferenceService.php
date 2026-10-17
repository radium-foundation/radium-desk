<?php

namespace App\Services\ReturnExchange;

use App\Models\ReferenceSequence;
use App\Services\OperationalReferenceSequenceService;

final class PosReturnRefundReferenceService
{
    private const FLOOR = 1000;

    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function generate(): string
    {
        return $this->sequences->allocate(
            ReferenceSequence::POS_RETURN_REFUND_OPERATIONAL,
            self::FLOOR,
            'PRR-',
        );
    }
}
