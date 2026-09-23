<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfPresentationException;
use App\Services\StatutoryInvoice\StatutoryInvoicePdfPresentationValidator;
use Tests\TestCase;

class StatutoryInvoicePdfPresentationValidatorTest extends TestCase
{
    public function test_validator_skips_when_e_invoice_is_not_submitted(): void
    {
        $invoice = new StatutoryInvoice;
        $invoice->setRelation('eInvoiceRecord', new EInvoiceRecord([
            'status' => EInvoiceRecordStatus::Queued->value,
            'irn' => null,
        ]));

        app(StatutoryInvoicePdfPresentationValidator::class)->validate(
            $invoice,
            '%PDF-1.4 no-irn',
        );

        $this->assertTrue(true);
    }

    public function test_validator_requires_irn_in_pdf_when_submitted(): void
    {
        $irn = str_repeat('a', 64);
        $invoice = new StatutoryInvoice;
        $invoice->setRelation('eInvoiceRecord', new EInvoiceRecord([
            'status' => EInvoiceRecordStatus::Submitted->value,
            'irn' => $irn,
            'ack_no' => 'ACK-1',
            'signed_qr' => 'eyJhbGciOiJFUzI1NiJ9.payload.signature',
        ]));

        $this->expectException(StatutoryInvoicePdfPresentationException::class);
        app(StatutoryInvoicePdfPresentationValidator::class)->validate(
            $invoice,
            '%PDF-1.4 missing-irn',
        );
    }
}
