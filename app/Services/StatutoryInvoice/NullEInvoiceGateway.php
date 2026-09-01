<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;

/**
 * Default adapter. Does not call media.radiumbox.com or any GSP.
 * IRN/QR/e-way are not submitted until a real provider is bound.
 */
final class NullEInvoiceGateway implements EInvoiceGateway
{
    public function provider(): string
    {
        return 'none';
    }

    public function submit(StatutoryInvoice $invoice): EInvoiceSubmitResult
    {
        return new EInvoiceSubmitResult(
            provider: $this->provider(),
            status: 'skipped',
        );
    }

    public function cancel(StatutoryInvoice $invoice, string $reason): void
    {
        // No remote IRN to cancel.
    }
}
