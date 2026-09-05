<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSequenceAllocation;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RdServiceNetPhase1CleanTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-rdservice-net-secret';

    private StatutoryInvoiceService $invoices;

    private StatutoryMintEligibility $eligibility;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->eligibility = app(StatutoryMintEligibility::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        config([
            'channel_ingest.secrets.rdservice_net' => self::SECRET,
            'channel_ingest.secrets.rdservice_in' => '',
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => 'TEST',
            'statutory_invoices.number_format' => '{series}-{seq:5}',
            'statutory_invoices.gstin_scope' => '07AAICP1128M1Z9',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.financial_year' => '',
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
        ]);
    }

    public function test_missing_secret_bad_signature_and_stale_timestamp_are_rejected(): void
    {
        config(['channel_ingest.secrets.rdservice_net' => '']);
        $this->signedPost($this->netPayload('RA3507100'), secret: 'guess')->assertUnauthorized();

        config(['channel_ingest.secrets.rdservice_net' => self::SECRET]);
        $this->signedPost($this->netPayload('RA3507101'), signature: 'deadbeef')->assertUnauthorized();
        $this->signedPost($this->netPayload('RA3507102'), timestamp: (string) (time() - 301))
            ->assertUnauthorized()
            ->assertJsonPath('status', 'replay');

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        Http::assertNothingSent();
    }

    public function test_valid_hmac_persists_a_paid_order_without_minting(): void
    {
        $this->signedPost($this->netPayload('RA3507103'))
            ->assertCreated()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('idempotency_key', 'statutory:rdservice_net:commerce_order:RA3507103');

        $order = CommerceOrder::query()->where('source_id', 'RA3507103')->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->invoice_eligible);
        $this->assertNull($order->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertFlagsRemainOff();
        Http::assertNothingSent();
    }

    public function test_duplicate_payload_is_idempotent_and_conflict_is_rejected(): void
    {
        $payload = $this->netPayload('RA3507104');
        $this->signedPost($payload)->assertCreated();
        $this->signedPost($payload)->assertOk()->assertJsonPath('duplicate', true);

        $conflict = $payload;
        $conflict['lines'][0]['unit_price'] = 200;
        $this->signedPost($conflict)->assertStatus(409)->assertJsonPath('status', 'conflict');

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_unpaid_order_is_not_invoice_eligible(): void
    {
        $payload = $this->netPayload('RA3507105');
        $payload['payment_status'] = 'pending';
        $this->signedPost($payload)
            ->assertCreated()
            ->assertJsonPath('invoice_eligible', false);

        $order = CommerceOrder::query()->where('source_id', 'RA3507105')->firstOrFail();
        $this->assertFalse($this->eligibility->evaluateOrder($order)->eligible);

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected unpaid order to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility', $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_manual_b2c_issue_allocates_a_number_and_private_pdf_without_irn(): void
    {
        $this->signedPost($this->netPayload('RA3507106'))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507106')->firstOrFail();

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $order = $order->fresh();
        $document = StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->first();

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);
        $this->assertSame(StatutoryInvoiceChannel::RdServiceNet, $invoice->channel);
        $this->assertSame(StatutoryInvoiceSourceType::CommerceOrder->value, $invoice->source_type);
        $this->assertSame('statutory:rdservice_net:commerce_order:RA3507106', $invoice->idempotency_key);
        $this->assertSame('07AAICP1128M1Z9', $invoice->seller_gstin);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame($invoice->id, $order?->statutory_invoice_id);
        $this->assertSame(CommerceOrderStatus::Invoiced, $order?->status);
        $this->assertSame(StatutoryInvoiceDocumentStatus::Generated, $document?->status);
        $this->assertSame('local', $document?->disk);
        Storage::disk('local')->assertExists((string) $document?->path);
        $this->assertStringStartsWith('%PDF-1.4', (string) Storage::disk('local')->get((string) $document?->path));
        $record = EInvoiceRecord::query()->first();
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame('b2c_not_eligible', $record?->response_payload['skip_reason'] ?? null);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertNull(EInvoiceRecord::query()->value('irn'));
        $this->assertFlagsRemainOff();
        Http::assertNothingSent();
    }

    public function test_manual_b2b_issue_queues_irn_outbox_without_http(): void
    {
        $this->signedPost($this->netPayload('RA3507107', [
            'customer' => [
                'name' => 'Buyer Industries',
                'phone' => '9000000002',
                'email' => 'buyer@example.com',
                'gstin' => '07AAAAA0000A1Z5',
            ],
        ]))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507107')->firstOrFail();

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $work = OutboxEvent::query()
            ->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->first();

        $this->assertSame('07AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertNotNull($work);
        $this->assertSame(OutboxEventStatus::Pending, $work->status);
        $this->assertSame(EInvoiceRecordStatus::Queued->value, EInvoiceRecord::query()->value('status'));
        $this->assertSame(0, EInvoiceRecord::query()->whereNotNull('irn')->count());
        Http::assertNothingSent();
    }

    public function test_invalid_gstin_and_missing_statutory_data_fail_closed(): void
    {
        $this->signedPost($this->netPayload('RA3507108', [
            'customer' => ['name' => 'Buyer', 'phone' => '9000000001', 'gstin' => 'NOT-A-GSTIN'],
        ]))->assertCreated();
        $invalid = CommerceOrder::query()->where('source_id', 'RA3507108')->firstOrFail();

        try {
            $this->invoices->issueFromCommerceOrder($invalid, $this->actor);
            $this->fail('Expected invalid GSTIN to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility', $exception->errors());
        }

        $payload = $this->netPayload('RA3507109');
        $payload['lines'][0]['hsn_sac'] = null;
        unset($payload['lines'][0]['taxable_value'], $payload['place_of_supply_state']);
        $this->signedPost($payload)->assertCreated();
        $incomplete = CommerceOrder::query()->where('source_id', 'RA3507109')->firstOrFail();

        try {
            $this->invoices->issueFromCommerceOrder($incomplete, $this->actor);
            $this->fail('Expected missing statutory data to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('eligibility', $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequence::query()->count());
    }

    public function test_document_get_authorizes_hmac_owner_and_denies_guest_wrong_customer_and_historical(): void
    {
        $this->signedPost($this->netPayload('RA3507110', [
            'customer' => ['name' => 'Owner', 'phone' => '9000000001', 'email' => 'owner@example.com'],
        ]))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507110')->firstOrFail();
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->signedGet('commerce_order', 'RA3507110')
            ->assertOk()
            ->assertJsonPath('statutory.invoice_number', $invoice->invoice_number)
            ->assertJsonPath('statutory.document_retrieval', 'hmac_document_get');
        $this->assertArrayNotHasKey('pdf_url', $this->signedGet('commerce_order', 'RA3507110')->json());

        $owner = $this->signedDocumentGet('commerce_order', 'RA3507110');
        $owner->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', $owner->getContent());
        $owner->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringNotContainsString(storage_path(), $owner->headers->get('Content-Disposition') ?? '');

        $this->call('GET', '/api/v1/channel-orders/commerce_order/RA3507110/document', [], [], [], [
            'HTTP_ACCEPT' => 'application/pdf',
        ])->assertUnauthorized();

        $this->signedDocumentGet('commerce_order', 'RA3507110', customer: 'stranger@example.com')
            ->assertNotFound();

        $historical = $this->netPayload('RA3506774');
        $historical['ordered_at'] = '2026-08-31 16:00:00';
        $this->signedPost($historical)->assertCreated();
        $this->signedDocumentGet('commerce_order', 'RA3506774')->assertForbidden();

        $this->signedDocumentGet('commerce_order', 'missing-id')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_retry_does_not_allocate_a_second_number(): void
    {
        $this->signedPost($this->netPayload('RA3507111'))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507111')->firstOrFail();

        $first = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $second = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('INV-07671', $second->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(1, (int) InvoiceSequence::query()->value('current_value'));
    }

    public function test_pdf_failure_keeps_the_allocated_number(): void
    {
        $this->signedPost($this->netPayload('RA3507112'))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507112')->firstOrFail();
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $number = $invoice->invoice_number;

        $renderer = $this->createMock(SimplePdfRenderer::class);
        $renderer->method('render')->willThrowException(new \RuntimeException('PDF renderer failed'));
        $this->app->instance(SimplePdfRenderer::class, $renderer);
        $this->app->forgetInstance(StatutoryDocumentService::class);

        try {
            app(StatutoryDocumentService::class)->generate($invoice);
            $this->fail('Expected PDF generation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PDF renderer failed', $exception->getMessage());
        }

        $this->assertSame($number, $invoice->fresh()->invoice_number);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(
            StatutoryInvoiceDocumentStatus::Failed,
            StatutoryInvoiceDocument::query()->where('invoice_id', $invoice->id)->first()?->status,
        );
    }

    public function test_admin_can_issue_from_the_finance_bridge(): void
    {
        $this->signedPost($this->netPayload('RA3507113'))->assertCreated();
        $order = CommerceOrder::query()->where('source_id', 'RA3507113')->firstOrFail();

        $this->actingAs($this->actor)
            ->post(route('finance.invoices.commerce-orders.issue', $order))
            ->assertRedirect(route('finance.invoices.commerce-orders.show', $order));

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('INV-07671', StatutoryInvoice::query()->value('invoice_number'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function netPayload(string $sourceId, array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => StatutoryInvoiceChannel::RdServiceNet->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'cf_'.$sourceId,
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'ordered_at' => '2026-09-01 10:00:00',
            'customer' => [
                'name' => 'Walk-in Customer',
                'phone' => '9000000001',
                'email' => 'owner@example.com',
                'gstin' => null,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Storefront name must be ignored',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'lines' => [
                [
                    'description' => 'Information technology (IT) consulting & support services',
                    'sku' => 'RD-SVC',
                    'qty' => 1,
                    'unit_price' => 100,
                    'hsn_sac' => '998313',
                    'gst_percentage' => 18,
                    'taxable_value' => 100,
                    'tax_total' => 18,
                    'line_total' => 118,
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(
        array $payload,
        ?string $secret = self::SECRET,
        ?string $signature = null,
        ?string $timestamp = null,
    ) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceNet->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => $signature ?? (new ChannelIngestAuthenticator)->signature($timestamp, $body, $secret),
        ], $body);
    }

    private function signedGet(string $sourceType, string $sourceId)
    {
        $timestamp = (string) time();

        return $this->call('GET', '/api/v1/channel-orders/'.$sourceType.'/'.$sourceId, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceNet->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, '', self::SECRET),
        ]);
    }

    private function signedDocumentGet(string $sourceType, string $sourceId, ?string $customer = null)
    {
        $timestamp = (string) time();
        $headers = [
            'HTTP_ACCEPT' => 'application/pdf',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceNet->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, '', self::SECRET),
        ];
        if ($customer !== null) {
            $headers['HTTP_X_DESK_CUSTOMER'] = $customer;
        }

        return $this->call('GET', '/api/v1/channel-orders/'.$sourceType.'/'.$sourceId.'/document', [], [], [], $headers);
    }

    private function assertFlagsRemainOff(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
    }
}
