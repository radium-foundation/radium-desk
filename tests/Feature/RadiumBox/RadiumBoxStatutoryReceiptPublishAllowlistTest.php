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
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPayload;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPolicy;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxStatutoryReceiptPublishAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_INVOICE_ID = 16543;

    private const BLOCKED_INVOICE_ID_16566 = 16566;

    private const BLOCKED_INVOICE_ID_16577 = 16577;

    private const BLOCKED_INVOICE_ID_16590 = 16590;

    private const BLOCKED_INVOICE_ID_16592 = 16592;

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
            'radiumbox.statutory_receipt_publish.allowed_invoice_ids' => [self::ALLOWED_INVOICE_ID],
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_publisher_disabled_blocks_http_even_when_invoice_is_on_allowlist(): void
    {
        config([
            'radiumbox.statutory_receipt_publish.enabled' => false,
            'radiumbox.statutory_receipt_publish.allowed_invoice_ids' => [self::ALLOWED_INVOICE_ID],
        ]);

        $invoice = $this->commerceInvoiceAtId(self::ALLOWED_INVOICE_ID, 'RBP1628', 'INV-6714817');
        $this->enqueuePublication($invoice);

        Http::fake();

        app(OutboxProcessorService::class)->process(1);

        Http::assertNothingSent();
        $publication = $this->publicationFor($invoice);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationPolicy::DISABLED_MESSAGE,
            $publication->last_error,
        );
    }

    public function test_allowlisted_invoice_one_six_five_four_three_can_deliver(): void
    {
        $invoice = $this->commerceInvoiceAtId(self::ALLOWED_INVOICE_ID, 'RBP1628', 'INV-6714817');
        $this->enqueuePublication($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(1);
        $publication = $this->publicationFor($invoice);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::Delivered, $publication->status);
    }

    public function test_invoices_one_six_five_six_six_and_one_six_five_seven_seven_cannot_deliver_under_allowlist(): void
    {
        Http::fake();

        foreach ([
            [self::BLOCKED_INVOICE_ID_16566, 'RB1258', 'INV-6714835'],
            [self::BLOCKED_INVOICE_ID_16577, 'RB1257', 'INV-6714845'],
        ] as [$invoiceId, $sourceId, $invoiceNumber]) {
            $invoice = $this->commerceInvoiceAtId($invoiceId, $sourceId, $invoiceNumber);
            $this->enqueuePublication($invoice);
            app(OutboxProcessorService::class)->process(1);

            $publication = $this->publicationFor($invoice);
            $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
            $this->assertSame(
                RadiumBoxStatutoryReceiptPublicationPolicy::ALLOWLIST_BLOCKED_MESSAGE,
                $publication->last_error,
            );
            $this->assertNull($publication->box_receipt_id);
        }

        Http::assertNothingSent();
    }

    public function test_invoices_one_six_five_nine_zero_and_one_six_five_nine_two_cannot_deliver_under_allowlist(): void
    {
        Http::fake();

        foreach ([
            [self::BLOCKED_INVOICE_ID_16590, 'RB1176', 'INV-6714857'],
            [self::BLOCKED_INVOICE_ID_16592, 'RB1186', 'INV-6714859'],
        ] as [$invoiceId, $sourceId, $invoiceNumber]) {
            $invoice = $this->commerceInvoiceAtId($invoiceId, $sourceId, $invoiceNumber);
            $this->enqueuePublication($invoice);
            app(OutboxProcessorService::class)->process(1);

            $publication = $this->publicationFor($invoice);
            $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
            $this->assertSame(
                RadiumBoxStatutoryReceiptPublicationPolicy::ALLOWLIST_BLOCKED_MESSAGE,
                $publication->last_error,
            );
        }

        Http::assertNothingSent();
    }

    public function test_newly_issued_eligible_invoice_cannot_deliver_under_allowlist(): void
    {
        Http::fake();

        $invoice = $this->commerceInvoiceAtId(16599, 'RB999001', 'INV-NEW-001');
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        app(OutboxProcessorService::class)->process(1);

        Http::assertNothingSent();
        $publication = $this->publicationFor($invoice);
        $this->assertNotNull($publication);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationPolicy::ALLOWLIST_BLOCKED_MESSAGE,
            $publication->last_error,
        );
    }

    public function test_global_outbox_processing_cannot_bypass_allowlist(): void
    {
        $allowed = $this->commerceInvoiceAtId(self::ALLOWED_INVOICE_ID, 'RBP1628', 'INV-6714817');
        $blocked = $this->commerceInvoiceAtId(self::BLOCKED_INVOICE_ID_16566, 'RB1258', 'INV-6714835');

        $this->enqueuePublication($blocked);
        $this->enqueuePublication($allowed);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(10);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($allowed) {
            return $request->hasHeader(
                'Idempotency-Key',
                RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($allowed),
            );
        });

        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::Delivered,
            $this->publicationFor($allowed)->status,
        );
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation,
            $this->publicationFor($blocked)->status,
        );
    }

    public function test_targeted_aggregate_processing_delivers_allowed_invoice(): void
    {
        $invoice = $this->commerceInvoiceAtId(self::ALLOWED_INVOICE_ID, 'RBP1628', 'INV-6714817');
        $this->enqueuePublication($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->processAggregate('statutory_invoice', self::ALLOWED_INVOICE_ID);

        Http::assertSentCount(1);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::Delivered,
            $this->publicationFor($invoice)->status,
        );
    }

    public function test_targeted_aggregate_processing_does_not_deliver_blocked_invoice(): void
    {
        $invoice = $this->commerceInvoiceAtId(self::BLOCKED_INVOICE_ID_16566, 'RB1258', 'INV-6714835');
        $this->enqueuePublication($invoice);

        Http::fake();

        app(OutboxProcessorService::class)->processAggregate('statutory_invoice', self::BLOCKED_INVOICE_ID_16566);

        Http::assertNothingSent();
        $publication = $this->publicationFor($invoice);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
        $this->assertFalse($publication->isTerminalSuccess());
    }

    public function test_blocked_publication_remains_retryable_after_allowlist_change(): void
    {
        $invoice = $this->commerceInvoiceAtId(self::BLOCKED_INVOICE_ID_16566, 'RB1258', 'INV-6714835');
        $this->enqueuePublication($invoice);

        app(OutboxProcessorService::class)->process(1);

        $blocked = $this->publicationFor($invoice);
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $blocked->status);
        $this->assertFalse($blocked->isTerminalSuccess());

        config(['radiumbox.statutory_receipt_publish.allowed_invoice_ids' => [self::BLOCKED_INVOICE_ID_16566]]);

        $this->assertSame(1, app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->requeueAwaitingActivation());

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB1'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(1);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::Delivered,
            $this->publicationFor($invoice)->status,
        );
    }

    public function test_http_idempotency_key_stable_across_retry_under_allowlist(): void
    {
        $invoice = $this->commerceInvoiceAtId(self::ALLOWED_INVOICE_ID, 'RBP1628', 'INV-6714817');
        $expectedKey = RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice);
        $this->enqueuePublication($invoice);

        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => 'down'], 503)
                ->push([
                    'status' => 'accepted',
                    'duplicate_delivery' => true,
                    'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
                ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);
        $outbox = OutboxEvent::query()->where('aggregate_id', $invoice->id)->first();
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);

        $outbox->update(['available_at' => now(), 'status' => OutboxEventStatus::Pending]);
        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(2);
        $keys = collect(Http::recorded())->map(
            fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? '',
        )->all();
        $this->assertSame([$expectedKey, $expectedKey], $keys);
        $this->assertSame($expectedKey, $this->publicationFor($invoice)->http_idempotency_key);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::DuplicateDelivered,
            $this->publicationFor($invoice)->status,
        );
    }

    public function test_empty_allowlist_preserves_unrestricted_delivery_when_enabled(): void
    {
        config(['radiumbox.statutory_receipt_publish.allowed_invoice_ids' => []]);

        $invoice = $this->commerceInvoiceAtId(self::BLOCKED_INVOICE_ID_16566, 'RB1258', 'INV-6714835');
        $this->enqueuePublication($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            ], 200),
        ]);

        app(OutboxProcessorService::class)->process(1);

        Http::assertSentCount(1);
        $this->assertSame(
            RadiumBoxStatutoryReceiptPublicationStatus::Delivered,
            $this->publicationFor($invoice)->status,
        );
    }

    public function test_publisher_defaults_remain_disabled_without_environment_overrides(): void
    {
        config([
            'radiumbox.statutory_receipt_publish.enabled' => filter_var(
                env('RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'radiumbox.statutory_receipt_publish.allowed_invoice_ids' => RadiumBoxStatutoryReceiptPublicationPolicy::parseAllowedInvoiceIdsFromEnv(
                env('RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ALLOWED_INVOICE_IDS'),
            ),
        ]);

        $this->assertFalse(config('radiumbox.statutory_receipt_publish.enabled'));
        $this->assertSame([], config('radiumbox.statutory_receipt_publish.allowed_invoice_ids'));
    }

    private function enqueuePublication(StatutoryInvoice $invoice): OutboxEvent
    {
        $event = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        $this->assertNotNull($event);

        return $event->fresh() ?? $event;
    }

    private function publicationFor(StatutoryInvoice $invoice): RadiumBoxStatutoryReceiptPublication
    {
        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $this->assertNotNull($publication);

        return $publication;
    }

    private function commerceInvoiceAtId(int $invoiceId, string $sourceId, string $invoiceNumber): StatutoryInvoice
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('sqlite_sequence')->updateOrInsert(
                ['name' => 'statutory_invoices'],
                ['seq' => $invoiceId - 1],
            );
        }

        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId.':'.$invoiceId,
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
            'invoice_value' => '100.00',
            'issued_at' => now(),
        ]);

        $this->assertSame($invoiceId, $invoice->id);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Goods',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => '100.00',
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
