<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\ServiceSacResolver;
use App\Services\StatutoryInvoice\ServiceStatutoryClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskServiceConsultancySac998312ClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_desk_service_consultancy_resolves_998312_profile(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'desk_service',
            null,
            'Consultancy Fees',
            '998312',
        );

        $this->assertNotNull($profile);
        $this->assertSame('998312', $profile->sac);
        $this->assertSame('desk_consultancy', $profile->serviceKey);
        $this->assertSame('Y', $profile->isServc);
        $this->assertSame('OTH', $profile->uqc);
    }

    public function test_null_line_uqc_falls_back_to_configured_oth_on_invoice_item(): void
    {
        $invoice = StatutoryInvoice::make([
            'channel' => StatutoryInvoiceChannel::DeskService,
        ]);
        $item = StatutoryInvoiceItem::make([
            'hsn_sac' => '998312',
            'description' => 'Consultancy Fees',
            'sku' => null,
            'uqc' => null,
        ]);

        $profile = (new ServiceStatutoryClassification)->profileForInvoiceItem($invoice, $item);

        $this->assertNotNull($profile);
        $this->assertSame('OTH', $profile->uqc);
    }

    public function test_mapper_payload_has_unit_oth_and_is_servc_y_without_uqc_or_is_servc_gaps(): void
    {
        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);

        $invoice = $this->makeDeskServiceConsultancyInvoiceShape();
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('OTH', $payload->items[0]['unit']);
        $this->assertSame('Y', $payload->items[0]['is_servc']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertNotContains('missing_is_servc', $payload->gaps);
    }

    public function test_freight_classification_for_998311_remains_unchanged(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'desk_service',
            'RBSMARKETS',
            'Secondary Freight Reverse Auction',
            '998311',
        );

        $this->assertNotNull($profile);
        $this->assertSame('998311', $profile->sac);
        $this->assertSame('desk_freight_auction', $profile->serviceKey);
        $this->assertSame('OTH', $profile->uqc);

        $sac = (new ServiceSacResolver)->resolve(
            'desk_service',
            'RBSMARKETS',
            'Secondary Freight Reverse Auction',
            '998311',
        );

        $this->assertSame('998311', $sac);
    }

    public function test_non_desk_service_channel_does_not_inherit_consultancy_rule(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'rdservice_in',
            null,
            'Consultancy Fees',
            '998312',
        );

        $this->assertNull($profile);
    }

    public function test_desk_service_without_matching_sac_or_needle_has_no_consultancy_profile(): void
    {
        $profile = (new ServiceStatutoryClassification)->profileForCommerceLine(
            'desk_service',
            null,
            'Annual retainer',
            '998399',
        );

        $this->assertNull($profile);
    }

    public function test_desk_service_sac_998312_matches_profile_without_consultancy_needle_in_description(): void
    {
        $invoice = StatutoryInvoice::make([
            'channel' => StatutoryInvoiceChannel::DeskService,
        ]);
        $item = StatutoryInvoiceItem::make([
            'hsn_sac' => '998312',
            'description' => 'Professional fees Q4',
            'sku' => null,
            'uqc' => null,
        ]);

        $profile = (new ServiceStatutoryClassification)->profileForInvoiceItem($invoice, $item);

        $this->assertNotNull($profile);
        $this->assertSame('desk_consultancy', $profile->serviceKey);
        $this->assertSame('998312', $profile->sac);
        $this->assertSame('OTH', $profile->uqc);
    }

    private function makeDeskServiceConsultancyInvoiceShape(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-SVC680-FIXTURE',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-680-FIXTURE',
            'idempotency_key' => 'statutory:desk-consultancy:998312',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '27AAAAA0000A1Z5',
            'billing_address' => 'Pune address',
            'billing_address_structured' => [
                'line1' => 'Line 1',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'pincode' => '411001',
            ],
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '18.00',
            'rounding' => '0.00',
            'invoice_value' => '118.00',
            'issued_at' => '2026-10-28 18:04:31',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => null,
            'description' => 'Consultancy Fees',
            'hsn_sac' => '998312',
            'uqc' => null,
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '18.00',
            'line_total' => '118.00',
        ]);

        return $invoice->fresh(['items']);
    }
}
