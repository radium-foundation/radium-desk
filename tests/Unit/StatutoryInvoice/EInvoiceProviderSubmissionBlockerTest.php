<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\EInvoiceProviderSubmissionBlocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EInvoiceProviderSubmissionBlockerTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_provider_error_codes_to_agent_safe_reasons(): void
    {
        $invoice = $this->freshInvoice('INV-BLOCKER-3028');
        $blocker = app(EInvoiceProviderSubmissionBlocker::class);

        $this->assertSame(
            'gstin_requires_verification',
            $blocker->blockedReason($this->record($invoice, '3028')),
        );

        $invoice = $this->freshInvoice('INV-BLOCKER-3038');
        $this->assertSame(
            'buyer_pin_requires_verification',
            $blocker->blockedReason($this->record($invoice, '3038')),
        );

        $invoice = $this->freshInvoice('INV-BLOCKER-3039');
        $this->assertSame(
            'buyer_pin_gstin_state_mismatch',
            $blocker->blockedReason($this->record($invoice, '3039')),
        );

        $invoice = $this->freshInvoice('INV-BLOCKER-9999');
        $this->assertNull($blocker->blockedReason($this->record($invoice, '9999')));
    }

    private function freshInvoice(string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $invoiceNumber,
            'idempotency_key' => 'statutory:blocker:'.$invoiceNumber,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => '10BOFPA3436O1Z4',
            'billing_address' => 'Billing',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '18.00',
            'rounding' => '0.00',
            'invoice_value' => '118.00',
            'issued_at' => '2026-09-10 10:00:00',
        ]);
    }

    private function record(StatutoryInvoice $invoice, string $code): EInvoiceRecord
    {
        return EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::PermanentFailure,
            'response_payload' => [
                'payload' => [
                    'status_desc' => '[{"errorCode":"'.$code.'","errorMessage":"blocked"}]',
                ],
            ],
        ]);
    }
}
