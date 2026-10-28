<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceRequestPayloadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class EInvoiceRequestPayloadSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeEInvoiceGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $this->gateway);

        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value,
        ]);
    }

    public function test_sync_persists_fresh_request_payload_for_queued_record(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();

        $result = app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('queued_payload_synced', $result['outcome']);
        $this->assertSame(2, $result['before']['gap_count']);
        $this->assertSame(0, $result['after']['gap_count']);
        $this->assertSame('OTH', $result['after']['derived_unit']);
        $this->assertSame('Y', $result['after']['derived_is_servc']);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record->status);
        $this->assertNotContains('missing_uqc', $record->request_payload['gaps'] ?? []);
        $this->assertNotContains('missing_is_servc', $record->request_payload['gaps'] ?? []);
    }

    public function test_consultancy_invoice_shape_matches_15468_classification_outcome(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();

        app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('OTH', $record->request_payload['items'][0]['unit'] ?? null);
        $this->assertSame('Y', $record->request_payload['items'][0]['is_servc'] ?? null);
        $this->assertSame([], $record->request_payload['gaps'] ?? []);
    }

    public function test_invoice_header_and_line_rows_remain_unchanged(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();
        $headerSnapshot = $invoice->only([
            'invoice_number', 'taxable_value', 'tax_total', 'invoice_value', 'buyer_gstin', 'seller_gstin',
        ]);
        $lineSnapshot = $invoice->items->first()?->only([
            'hsn_sac', 'description', 'uqc', 'qty', 'taxable_value', 'tax_total', 'line_total',
        ]);

        app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $invoice->refresh();
        $this->assertSame($headerSnapshot, $invoice->only(array_keys($headerSnapshot)));
        $this->assertSame($lineSnapshot, $invoice->items->first()?->only(array_keys($lineSnapshot ?? [])));
    }

    public function test_incomplete_payload_records_skipped_outcome_without_submit(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-INCOMPLETE',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-INCOMPLETE',
            'idempotency_key' => 'statutory:incomplete',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => null,
            'billing_address' => 'Address',
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

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Queued->value,
            'request_payload' => ['gaps' => ['missing_buyer_gstin']],
            'response_payload' => ['queue_reason' => 'eligible'],
        ]);

        $result = app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('skipped_irp_fields_incomplete', $result['outcome']);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record->status);
        $this->assertSame(0, $this->gateway->submitCount);
        $this->assertSame(0, $this->gateway->fetchCount);
    }

    public function test_no_gateway_outbox_or_queue_side_effects(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();
        OutboxEvent::query()->create([
            'idempotency_key' => EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice),
            'event_type' => EInvoiceOutboxWriter::EVENT_TYPE,
            'aggregate_type' => EInvoiceOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $invoice->id,
            'payload' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now(),
        ]);
        $outboxBefore = OutboxEvent::query()->count();

        app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $this->assertSame(0, $this->gateway->submitCount);
        $this->assertSame(0, $this->gateway->fetchCount);
        $this->assertSame($outboxBefore, OutboxEvent::query()->count());
        $this->assertSame(
            OutboxEventStatus::Completed,
            OutboxEvent::query()->first()?->status,
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_invalid_and_missing_invoice_ids_fail_safely(): void
    {
        $sync = app(EInvoiceRequestPayloadSyncService::class);

        $invalid = $sync->sync(0);
        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid_invoice_id', $invalid['error_code']);

        $missing = $sync->sync(999999);
        $this->assertFalse($missing['ok']);
        $this->assertSame('invoice_not_found', $missing['error_code']);
    }

    public function test_repeated_sync_is_idempotent(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();
        $sync = app(EInvoiceRequestPayloadSyncService::class);

        $first = $sync->sync($invoice->id);
        $second = $sync->sync($invoice->id);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['after']['gap_codes'], $second['after']['gap_codes']);
        $this->assertSame(0, $this->gateway->submitCount);
    }

    public function test_processing_status_blocks_sync(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'status' => EInvoiceRecordStatus::Processing->value,
        ]);

        $result = app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('recover_required', $result['error_code']);
    }

    public function test_irn_present_blocks_sync(): void
    {
        $invoice = $this->createConsultancyInvoiceWithStaleGaps();
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'a'.str_repeat('b', 63),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        $result = app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('irn_already_present', $result['error_code']);
    }

    public function test_artisan_command_rejects_non_integer_invoice_id(): void
    {
        $this->artisan('desk:einvoice-sync-request-payload', ['invoice_id' => '15468x'])
            ->assertFailed();
    }

    private function createConsultancyInvoiceWithStaleGaps(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-0767652',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-680',
            'idempotency_key' => 'statutory:desk-consultancy:998312-sync-test',
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

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Queued->value,
            'request_payload' => [
                'gaps' => ['missing_uqc', 'missing_is_servc'],
                'items' => [['unit' => null, 'is_servc' => null]],
            ],
            'response_payload' => ['queue_reason' => 'eligible', 'reevaluated_at' => '2026-10-28T18:07:51+05:30'],
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
