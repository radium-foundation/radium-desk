<?php

namespace Tests\Unit\RadiumBox;

use App\Enums\RadiumBox\RadiumBoxStatutoryReceiptPublicationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationClient;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPayload;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationPermanentFailureException;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Deterministic Desk→RadiumBox statutory receipt contract (v1).
 *
 * Receiver reference: radiumbox.com merge 6fd4394027b7692d117a52f98524fba89a367a6b
 * (POST /api/integrations/v1/desk-statutory-invoices, Bearer + Idempotency-Key).
 */
class RadiumBoxStatutoryInvoiceReceiptApiContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen request body aligned with radiumbox.com/desk-statutory-invoices/v1.
     *
     * @var array<string, mixed>
     */
    private const FIXTURE_BODY = [
        'channel' => 'radiumbox_com',
        'source_type' => 'commerce_order',
        'source_id' => 'RB317421',
        'desk_statutory_invoice_id' => 16543,
        'desk_invoice_number' => 'INV-673001',
        'invoice_total' => '1180.00',
        'issued_at' => '2026-10-31T12:00:00+00:00',
    ];

    private const FIXTURE_IDEMPOTENCY_KEY = 'desk-statutory:radiumbox_com:commerce_order:RB317421:16543';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));

        config([
            'app.timezone' => 'UTC',
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-statutory-token',
            'radiumbox.statutory_receipt_publish.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payload_builder_matches_frozen_box_v1_fixture(): void
    {
        $invoice = $this->invoiceFromFixture();

        $body = RadiumBoxStatutoryReceiptPublicationPayload::fromInvoice($invoice);

        $this->assertSame(self::FIXTURE_BODY['channel'], $body['channel']);
        $this->assertSame(self::FIXTURE_BODY['source_type'], $body['source_type']);
        $this->assertSame(self::FIXTURE_BODY['source_id'], $body['source_id']);
        $this->assertSame($invoice->id, $body['desk_statutory_invoice_id']);
        $this->assertSame(self::FIXTURE_BODY['desk_invoice_number'], $body['desk_invoice_number']);
        $this->assertSame(self::FIXTURE_BODY['invoice_total'], $body['invoice_total']);
        $this->assertArrayHasKey('issued_at', $body);

        $expectedKey = sprintf(
            'desk-statutory:%s:%s:%s:%d',
            self::FIXTURE_BODY['channel'],
            self::FIXTURE_BODY['source_type'],
            self::FIXTURE_BODY['source_id'],
            $invoice->id,
        );
        $this->assertSame($expectedKey, RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice));
    }

    public function test_client_posts_exact_endpoint_bearer_and_idempotency_header(): void
    {
        $invoice = $this->invoiceFromFixture();
        $idempotencyKey = RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/desk-statutory-invoices' => Http::response([
                'status' => 'accepted',
                'currency' => 'INR',
                'duplicate_delivery' => false,
                'receipt' => ['receipt_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAY'],
            ], 200),
        ]);

        $result = app(RadiumBoxStatutoryReceiptPublicationClient::class)->publish(
            $invoice,
            $idempotencyKey,
        );

        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAY', $result->boxReceiptId);
        $this->assertFalse($result->retryable);

        Http::assertSent(function ($request) use ($idempotencyKey) {
            $data = $request->data();

            return $request->url() === 'https://radiumbox.test'.RadiumBoxStatutoryReceiptPublicationClient::ENDPOINT_PATH
                && $request->hasHeader('Authorization', 'Bearer desk-statutory-token')
                && ($request->header('Idempotency-Key')[0] ?? '') === $idempotencyKey
                && $data['channel'] === self::FIXTURE_BODY['channel']
                && $data['source_type'] === self::FIXTURE_BODY['source_type']
                && $data['source_id'] === self::FIXTURE_BODY['source_id']
                && $data['desk_invoice_number'] === self::FIXTURE_BODY['desk_invoice_number']
                && $data['invoice_total'] === self::FIXTURE_BODY['invoice_total'];
        });
    }

    public function test_missing_token_is_not_configured_and_does_not_http(): void
    {
        config(['order_lookup.spokes.radiumbox_com.token' => '']);

        $invoice = $this->invoiceFromFixture();

        Http::fake();

        $this->expectException(RadiumBoxStatutoryReceiptPublicationPermanentFailureException::class);

        app(RadiumBoxStatutoryReceiptPublicationClient::class)->publish(
            $invoice,
            RadiumBoxStatutoryReceiptPublicationPayload::httpIdempotencyKey($invoice),
        );

        Http::assertNothingSent();
    }

    public function test_amount_mismatch_maps_to_permanent_failure_with_eligibility_code(): void
    {
        $invoice = $this->invoiceFromFixture();
        $event = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        $this->assertNotNull($event);

        Http::fake([
            '*' => Http::response([
                'message' => 'Invoice total does not match order billing total.',
                'eligibility' => 'amount_mismatch',
            ], 409),
        ]);

        try {
            app(RadiumBoxStatutoryReceiptPublicationProcessor::class)->process($event->fresh());
        } catch (RadiumBoxStatutoryReceiptPublicationPermanentFailureException) {
            // Expected terminal classification path.
        }

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::FailedPermanent, $publication->status);
        $this->assertSame('amount_mismatch', $publication->eligibility_code);
        $this->assertSame(409, $publication->last_http_status);
    }

    public function test_disabled_publisher_completes_awaiting_activation_without_http(): void
    {
        config(['radiumbox.statutory_receipt_publish.enabled' => false]);

        $invoice = $this->invoiceFromFixture();
        $event = app(RadiumBoxStatutoryReceiptPublicationOutboxWriter::class)->enqueue($invoice);
        $this->assertNotNull($event);

        Http::fake();

        app(OutboxProcessorService::class)->process(1);

        Http::assertNothingSent();

        $publication = RadiumBoxStatutoryReceiptPublication::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->firstOrFail();
        $this->assertSame(RadiumBoxStatutoryReceiptPublicationStatus::AwaitingActivation, $publication->status);
    }

    private function invoiceFromFixture(): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => self::FIXTURE_BODY['desk_invoice_number'],
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => self::FIXTURE_BODY['source_id'],
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.self::FIXTURE_BODY['source_id'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'billing_address' => 'Addr',
            'taxable_value' => '1000.00',
            'discount' => '0.00',
            'tax_total' => '180.00',
            'cgst' => '90.00',
            'sgst' => '90.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => self::FIXTURE_BODY['invoice_total'],
            'issued_at' => Carbon::parse(self::FIXTURE_BODY['issued_at'], 'UTC'),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Goods',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => self::FIXTURE_BODY['invoice_total'],
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '1000.00',
            'tax_total' => '180.00',
            'cgst' => '90.00',
            'sgst' => '90.00',
            'igst' => '0.00',
            'line_total' => self::FIXTURE_BODY['invoice_total'],
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
