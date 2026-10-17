<?php

namespace App\Services\ReturnExchange\Data;

final class ReturnExchangeCaseTaxEvidenceInvoice
{
    /**
     * @param  list<string>  $creditNoteSummaries
     */
    public function __construct(
        public readonly string $linkLabel,
        public readonly string $invoiceNumber,
        public readonly string $documentTypeLabel,
        public readonly string $statusLabel,
        public readonly ?string $issuedAt,
        public readonly string $irnState,
        public readonly ?string $cancellationSummary,
        public readonly array $creditNoteSummaries,
        public readonly string $ewayState,
    ) {}
}
