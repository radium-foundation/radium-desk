<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;

/**
 * Get-IRN-only surface. Does not expose submit() or cancel().
 * Do not bind this as EInvoiceGateway. GENERATE uses WhitebooksEInvoiceGateway
 * when STATUTORY_EINVOICE_PROVIDER=whitebooks.
 */
final class WhitebooksIrnRecoveryGateway
{
    public function __construct(
        private readonly WhitebooksEInvoiceGateway $whitebooks,
    ) {}

    public function provider(): string
    {
        return $this->whitebooks->provider();
    }

    public function fetchExisting(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): EInvoiceSubmitResult
    {
        return $this->whitebooks->fetchExisting($invoice, $payload);
    }
}
