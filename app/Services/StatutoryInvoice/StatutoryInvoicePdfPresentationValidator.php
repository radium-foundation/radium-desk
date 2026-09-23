<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\StatutoryInvoice;

class StatutoryInvoicePdfPresentationValidator
{
    public function validate(StatutoryInvoice $invoice, string $binary): void
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || $record->status !== EInvoiceRecordStatus::Submitted->value) {
            return;
        }

        $irn = trim((string) $record->irn);
        if ($irn !== '' && ! str_contains($binary, $irn)) {
            throw new StatutoryInvoicePdfPresentationException(
                'Generated statutory PDF does not contain the issued IRN.',
            );
        }

        $ackNo = trim((string) ($record->ack_no ?? ''));
        if ($ackNo !== '' && ! str_contains($binary, $ackNo)) {
            throw new StatutoryInvoicePdfPresentationException(
                'Generated statutory PDF does not contain the e-invoice acknowledgement number.',
            );
        }

        $signedQr = trim((string) ($record->signed_qr ?? ''));
        if ($signedQr !== '' && ! str_contains($binary, '% signed-qr-image')) {
            throw new StatutoryInvoicePdfPresentationException(
                'Generated statutory PDF does not contain the signed e-invoice QR image.',
            );
        }

        if ($irn !== '' && ! str_contains($binary, 'e-Invoice Verification')) {
            throw new StatutoryInvoicePdfPresentationException(
                'Generated statutory PDF does not contain the e-invoice verification block.',
            );
        }
    }
}
