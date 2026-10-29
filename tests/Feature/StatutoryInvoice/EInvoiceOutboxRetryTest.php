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
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceRequestPayloadSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class EInvoiceOutboxRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_desk_service_consultancy_998312_submits_oth_and_is_servc_via_worker(): void
    {
        $invoice = $this->consultancyInvoice();
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Queued->value,
            'response_payload' => ['queue_reason' => 'eligible'],
        ]);

        app(EInvoiceOutboxWriter::class)->write($invoice);

        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertTrue($record->hasIssuedIrn());
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame('OTH', $record->request_payload['items'][0]['unit'] ?? null);
        $this->assertSame('Y', $record->request_payload['items'][0]['is_servc'] ?? null);
        $this->assertSame(OutboxEventStatus::Completed, OutboxEvent::query()->first()?->status);
    }

    public function test_payload_sync_reopens_completed_outbox_and_worker_submits_without_backfill(): void
    {
        $invoice = $this->consultancyInvoice();
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Skipped->value,
            'request_payload' => ['gaps' => ['missing_uqc', 'missing_is_servc']],
            'response_payload' => ['skip_reason' => 'irp_fields_incomplete'],
        ]);

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

        $result = app(EInvoiceRequestPayloadSyncService::class)->sync($invoice->id);
        $this->assertTrue($result['ok']);
        $this->assertSame(OutboxEventStatus::Pending, OutboxEvent::query()->first()?->status);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue(EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first()?->hasIssuedIrn());
    }

    public function test_issued_irn_never_reopens_terminal_outbox(): void
    {
        $invoice = $this->consultancyInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'irn' => str_repeat('a', 64),
            'ack_no' => 'ACK-1',
            'response_payload' => [],
        ]);

        $event = OutboxEvent::query()->create([
            'idempotency_key' => EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice),
            'event_type' => EInvoiceOutboxWriter::EVENT_TYPE,
            'aggregate_type' => EInvoiceOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $invoice->id,
            'payload' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now(),
        ]);

        app(EInvoiceOutboxWriter::class)->write($invoice->fresh(['items']));

        $this->assertSame(OutboxEventStatus::Completed, $event->fresh()->status);
    }

    public function test_ambiguous_record_never_reopens_terminal_outbox(): void
    {
        $invoice = $this->consultancyInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Ambiguous->value,
            'response_payload' => ['ambiguous' => true],
        ]);

        $event = OutboxEvent::query()->create([
            'idempotency_key' => EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice),
            'event_type' => EInvoiceOutboxWriter::EVENT_TYPE,
            'aggregate_type' => EInvoiceOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $invoice->id,
            'payload' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 2,
            'available_at' => now(),
        ]);

        app(EInvoiceOutboxWriter::class)->write($invoice->fresh(['items']));

        $this->assertSame(OutboxEventStatus::Completed, $event->fresh()->status);
    }

    public function test_repeated_write_after_reopen_stays_idempotent(): void
    {
        $invoice = $this->consultancyInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Queued->value,
            'response_payload' => ['queue_reason' => 'eligible'],
        ]);

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

        $writer = app(EInvoiceOutboxWriter::class);
        $writer->write($invoice->fresh(['items']));
        $writer->write($invoice->fresh(['items']));

        $this->assertSame(1, OutboxEvent::query()->count());
        $this->assertSame(OutboxEventStatus::Pending, OutboxEvent::query()->first()?->status);
    }

    public function test_processing_outbox_row_is_never_reopened(): void
    {
        $invoice = $this->consultancyInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'status' => EInvoiceRecordStatus::Queued->value,
            'response_payload' => ['queue_reason' => 'eligible'],
        ]);

        $event = OutboxEvent::query()->create([
            'idempotency_key' => EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice),
            'event_type' => EInvoiceOutboxWriter::EVENT_TYPE,
            'aggregate_type' => EInvoiceOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $invoice->id,
            'payload' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
            'status' => OutboxEventStatus::Processing,
            'attempts' => 1,
            'available_at' => now(),
        ]);

        app(EInvoiceOutboxWriter::class)->write($invoice->fresh(['items']));

        $this->assertSame(OutboxEventStatus::Processing, $event->fresh()->status);
    }

    private function consultancyInvoice(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-CONSULT-998312',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-CONSULT-998312',
            'idempotency_key' => 'statutory:desk-consultancy:998312-retry-test',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '27AAAAA0000A1Z5',
            'billing_address' => 'Gat No. 148/3 to 148/7, Chakan Talegaon Road, Mahalunge',
            'billing_address_structured' => [
                'line1' => 'Gat No. 148/3 to 148/7, Chakan Talegaon Road, Mahalunge',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'pincode' => '410501',
            ],
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => '95000.00',
            'discount' => '0.00',
            'tax_total' => '17100.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '17100.00',
            'rounding' => '0.00',
            'invoice_value' => '112100.00',
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
            'unit_price' => '95000.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '95000.00',
            'tax_total' => '17100.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '17100.00',
            'line_total' => '112100.00',
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
