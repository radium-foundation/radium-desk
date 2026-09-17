<?php

namespace App\Services;

use App\Models\ReferenceSequence;
use App\Support\OperationalReference\OperationalReferenceParser;

/**
 * Allocates independent refund operational references (REF-67315+).
 *
 * Historical year-based references (REF-YYYY-NNNNNN) remain untouched in the
 * database and do not advance this counter. Statutory invoice numbering (INV-*)
 * is handled separately.
 */
class RefundReferenceService
{
    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function generate(): string
    {
        return $this->sequences->allocate(
            ReferenceSequence::REFUND_OPERATIONAL,
            OperationalReferenceParser::REFUND_FLOOR,
            'REF-',
        );
    }

    public function peekNext(): int
    {
        return $this->sequences->peekNext(
            ReferenceSequence::REFUND_OPERATIONAL,
            OperationalReferenceParser::REFUND_FLOOR,
        );
    }
}
