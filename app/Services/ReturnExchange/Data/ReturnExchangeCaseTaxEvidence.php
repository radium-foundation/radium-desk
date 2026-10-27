<?php

namespace App\Services\ReturnExchange\Data;

final class ReturnExchangeCaseTaxEvidence
{
    /**
     * @param  list<ReturnExchangeCaseTaxEvidenceInvoice>  $invoices
     */
    public function __construct(
        public readonly string $sourceKindLabel,
        public readonly string $linkState,
        public readonly string $linkMessage,
        public readonly array $invoices,
    ) {}
}
