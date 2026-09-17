<?php

namespace App\Services;

use App\Models\ReferenceSequence;
use App\Support\OperationalReference\OperationalReferenceParser;

/**
 * Allocates independent service order operational references (SVC-671+).
 *
 * Legacy ID-derived references such as SVC-000001 remain untouched. Never derive
 * new service order numbers from the database primary key.
 */
class ServiceOrderReferenceService
{
    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function allocate(): string
    {
        return $this->sequences->allocate(
            ReferenceSequence::SERVICE_ORDER_OPERATIONAL,
            OperationalReferenceParser::SERVICE_ORDER_FLOOR,
            'SVC-',
        );
    }

    public function peekNext(): int
    {
        return $this->sequences->peekNext(
            ReferenceSequence::SERVICE_ORDER_OPERATIONAL,
            OperationalReferenceParser::SERVICE_ORDER_FLOOR,
        );
    }
}
