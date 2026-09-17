<?php

namespace App\Services;

use App\Models\ReferenceSequence;
use App\Support\OperationalReference\OperationalReferenceParser;

/**
 * Allocates independent Product POS operational references (POS-6720+).
 *
 * Legacy ID-derived references such as POS-000019 remain untouched. Never derive
 * new Product POS sale numbers from the database primary key. Statutory/internal
 * invoice numbering on inventory_sales.invoice_number (INV-*) is separate.
 */
class ProductPosReferenceService
{
    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function allocate(): string
    {
        return $this->sequences->allocate(
            ReferenceSequence::PRODUCT_POS_OPERATIONAL,
            OperationalReferenceParser::PRODUCT_POS_FLOOR,
            'POS-',
        );
    }

    public function peekNext(): int
    {
        return $this->sequences->peekNext(
            ReferenceSequence::PRODUCT_POS_OPERATIONAL,
            OperationalReferenceParser::PRODUCT_POS_FLOOR,
        );
    }
}
