<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\OutboxEventStatus;
use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\OutboxEvent;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationClient;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPayload;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RadiumBoxStatutoryReceiptPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-31 12:00:00');

        config([
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-statutory-token',
            'radiumbox.statutory_receipt_publish.enabled' => true,
            'radiumbox.statutory_receipt_publish.schedule_on_issue' => true,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_payload_and_http_idempotency_key_from_statutory_invoice(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317421', 'INV-673001', '1180.00');

        $body = RadiumBoxStatutoryReceiptPublicationPayload::fromInvoice($invoice);
        $this->assertSame('radiumbox_com', $body['channel']);
        $this->assertSame('commerce_order', $body['source_type']);
        $this->assertSame('RB317421', $body['source_id']);
        $this->assertSame($invoice->id, $body['desk_statutory_invoice_id']);
        $this->assertSame('INV-673001', $body['desk_invoice_number']);
        $this->assertSame('1180.00', $body['invoice_total']);
        $this->assertSame($invoice->issued_at?->toIso8601String(), $body['issued_at']);

        $expectedKey = sprintf(
            'desk-statutory:radiumbox_com:commerce_order:RB317421:%d',
            $invoice->id,
        );
        $this->assertSame($expectedKey, RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice));
    }

    public function test_enqueue_is_idempotent_for_same_invoice(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317422', 'INV-673002', '499.00');
        $writer = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class);

        $first = $writer->enqueue($invoice);
        $second = $writer->enqueue($invoice);

        $this->assertNotNull($first);
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->count());
    }

    public function test_successful_delivery_persists_receipt_and_completes_outbox(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317423', 'INV-673003', '999.00');
        $this->enqueuePublication($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertNotNull($publication);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, $publication->status);
        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $publication->box_receipt_id);
        $this->assertSame(200, $publication->last_http_status);
        $this->assertFalse($publication->duplicate_delivery);

        Http::assertSent(function ($request) use ($invoice) {
            return $request->hasHeader('Idempotency-Key', RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice))
                && $request->hasHeader('Authorization', 'Bearer desk-statutory-token');
        });

        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Completed, $outbox->status);
    }

    public function test_duplicate_delivery_acknowledgement(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317424', 'INV-673004', '100.00');
        $this->enqueuePublication($invoice);

        Http::fake([
            '*' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => true,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::DuplicateDelivered, $publication->status);
        $this->assertTrue($publication->duplicate_delivery);
    }

    public function test_http_idempotency_key_stable_across_retries(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317425', 'INV-673005', '50.00');
        $this->enqueuePublication($invoice);
        $expectedKey = RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice);

        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'down'], 503)
                ->push([
                    'status' => 'accepted',
                    'duplicate_delivery' => false,
                    'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX'],
                ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);
        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);

        $outbox->update(['available_at' => now(), 'status' => OutboxEventStatus::Pending]);
        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(2);
        $keys = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? '')->all();
        $this->assertSame([$expectedKey, $expectedKey], $keys);
    }

    #[DataProvider('permanentFailureStatusProvider')]
    public function test_permanent_http_failures_do_not_retry_indefinitely(int $status, array $json): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317426', 'INV-673006', '10.00');
        $this->enqueuePublication($invoice);

        Http::fake(['*' => Http::response($json, $status)]);

        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(1);
        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Failed, $outbox->status);

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent, $publication->status);
        $this->assertSame($status, $publication->last_http_status);
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>}>
     */
    public static function permanentFailureStatusProvider(): array
    {
        return [
            '401' => [401, ['message' => 'Unauthorized']],
            '404' => [404, ['message' => 'Not found', 'eligibility' => 'billing_link_required']],
            '409' => [409, ['message' => 'Conflict', 'eligibility' => 'amount_mismatch']],
            '422' => [422, ['message' => 'Validation failed']],
        ];
    }

    public function test_transient_5xx_retries_until_success(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317427', 'INV-673007', '20.00');
        $this->enqueuePublication($invoice);

        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'error'], 502)
                ->push([
                    'status' => 'accepted',
                    'duplicate_delivery' => false,
                    'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAY'],
                ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);
        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);

        $outbox->update(['available_at' => now()]);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('status'));
    }

    public function test_retry_exhaustion_marks_publication_failed(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317428', 'INV-673008', '30.00');
        $this->enqueuePublication($invoice);

        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

        for ($i = 0; $i < OutboxProcessorService::MAX_ATTEMPTS; $i++) {
            $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
            $outbox->update(['available_at' => now(), 'status' => OutboxEventStatus::Pending]);
            app(OutboxProcessorService::class)->process(1);
        }

        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Failed, $outbox->status);

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent, $publication->status);
        $this->assertSame(OutboxProcessorService::MAX_ATTEMPTS, $publication->attempt_count);
    }

    public function test_publisher_disabled_completes_outbox_without_http(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice('RB317429', 'INV-673009', '40.00');
        $this->enqueuePublication($invoice);

        Http::fake();

        app(OutboxProcessorService::class)->process(1);

        Http::assertNothingSent();
        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);

        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Completed, $outbox->status);
    }

    public function test_awaiting_activation_invoice_dispatches_after_enable_and_requeue(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice('RB317433', 'INV-673013', '88.00');
        $this->enqueuePublication($invoice);

        app(OutboxProcessorService::class)->process(1);

        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB0'],
            ], 200),
        ]);

        $requeued = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->requeueAwaitingActivation();
        $this->assertSame(1, $requeued);

        app(OutboxProcessorService::class)->process(1);

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, $publication->status);
        Http::assertSentCount(1);

        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Completed, $outbox->status);
    }

    public function test_requeue_awaiting_activation_is_idempotent(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice('RB317434', 'INV-673014', '99.00');
        $this->enqueuePublication($invoice);
        app(OutboxProcessorService::class)->process(1);

        $writer = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class);
        $this->assertSame(1, $writer->requeueAwaitingActivation());
        $this->assertSame(0, $writer->requeueAwaitingActivation());

        $this->assertSame(1, OutboxEvent::query()
            ->where('idempotency_key', RadiumBoxStatutoryReceiptPublicationOutboxWriter::outboxIdempotencyKey($invoice->id))
            ->where('status', OutboxEventStatus::Pending)
            ->count());
    }

    public function test_enqueue_reopens_completed_outbox_when_awaiting_activation(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice('RB317435', 'INV-673015', '77.50');
        $writer = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class);
        $writer->enqueue($invoice);
        app(OutboxProcessorService::class)->process(1);

        config(['radiumbox.statutory_receipt_publish.enabled' => true]);

        $writer->enqueue($invoice);

        $outbox = OutboxEvent::query()
            ->where('idempotency_key', RadiumBoxStatutoryReceiptPublicationOutboxWriter::outboxIdempotencyKey($invoice->id))
            ->first();
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);
    }

    public function test_failed_outbox_can_retry_http_without_duplicating_publication_row(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317436', 'INV-673016', '12.00');
        $this->enqueuePublication($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push([
                    'status' => 'accepted',
                    'duplicate_delivery' => false,
                    'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB1'],
                ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, RadiumBoxStatutoryReceiptPublication::query()->count());
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('status'));

        app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->value('status'));
        $this->assertSame(1, RadiumBoxStatutoryReceiptPublication::query()->count());
        Http::assertSentCount(2);
    }

    public function test_non_radiumbox_commerce_invoice_is_not_scheduled(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-RDS-1',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RD3512501',
            'idempotency_key' => 'statutory:rd:1',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'billing_address' => 'Addr',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '118.00',
            'issued_at' => now(),
        ]);

        app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);

        $this->assertSame(0, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->count());
    }

    public function test_queue_einvoice_hook_schedules_radiumbox_receipt_outbox(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->radiumBoxCommerceInvoice('RB317430', 'INV-673010', '55.00');

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', RadiumBoxStatutoryReceiptPublicationOutboxWriter::EVENT_TYPE)
            ->count());
    }

    public function test_concurrent_enqueue_creates_single_outbox_row(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317431', 'INV-673011', '66.00');
        $writer = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class);

        $writer->enqueue($invoice);
        $writer->enqueue($invoice);
        $writer->enqueue($invoice);

        $this->assertSame(1, OutboxEvent::query()
            ->where('idempotency_key', RadiumBoxStatutoryReceiptPublicationOutboxWriter::outboxIdempotencyKey($invoice->id))
            ->count());
        $this->assertSame(1, RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->count());
    }

    public function test_terminal_success_skips_second_http_on_reprocess(): void
    {
        $invoice = $this->radiumBoxCommerceInvoice('RB317432', 'INV-673012', '77.00');
        $event = $this->enqueuePublication($invoice);

        Http::fake([
            '*' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ'],
            ], 200),
        ]);

        app(RadiumBoxStatutoryReceiptPublicationProcessor::class)->process($event);
        Http::assertSentCount(1);

        Http::fake();
        app(RadiumBoxStatutoryReceiptPublicationProcessor::class)->process($event->fresh());
        Http::assertNothingSent();
    }

    private function enqueuePublication(StatutoryInvoice $invoice): OutboxEvent
    {
        $event = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        $this->assertNotNull($event);

        return $event->fresh() ?? $event;
    }

    private function radiumBoxCommerceInvoice(string $sourceId, string $invoiceNumber, string $invoiceValue): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'billing_address' => 'Addr',
            'taxable_value' => '100.00',
            'discount' => '0.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => $invoiceValue,
            'issued_at' => now(),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Goods',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => $invoiceValue,
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => $invoiceValue,
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
