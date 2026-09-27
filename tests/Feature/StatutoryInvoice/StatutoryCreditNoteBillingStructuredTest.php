<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceCreditNoteService;
use Tests\Support\FakeEInvoiceGateway;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryCreditNoteBillingStructuredTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.issuance_policy' => 'all_eligible_b2b',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_commerce_original_without_invoice_structured_inherits_complete_billing_on_credit_note(): void
    {
        $original = $this->createCommerceOriginal([
            'billing_address_structured' => null,
        ]);
        $this->createCommerceOrder($original, $this->jharsugudaStructured());
        $this->attachSubmittedIrn($original);

        $creditNote = $this->issueCreditNote($original);

        $this->assertSame($this->jharsugudaStructured(), $creditNote->billing_address_structured);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($creditNote->fresh(['items', 'originalStatutoryInvoice.eInvoiceRecord']));
        $this->assertNotContains('missing_buyer_pin', $payload->gaps, implode(', ', $payload->gaps));
        $this->assertNotContains('missing_buyer_loc', $payload->gaps, implode(', ', $payload->gaps));
        $this->assertTrue($payload->isSubmittable(), implode(', ', $payload->gaps));
        $this->assertSame('768201', $payload->buyer['pin']);
        $this->assertSame('Jharsuguda', $payload->buyer['location']);
    }

    public function test_pos_sale_structured_billing_is_snapshotted_on_credit_note(): void
    {
        $branch = $this->inventoryBranch();
        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-CN-BILLING-1',
            'branch_id' => $branch->id,
            'status' => 'completed',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'billing_address_structured' => $this->delhiStructured(),
            'completed_at' => now()->subDays(10),
        ]);
        $original = $this->createPosOriginal($sale);
        $this->attachSubmittedIrn($original);

        $creditNote = $this->issueCreditNote($original);

        $this->assertSame($this->delhiStructured(), $creditNote->billing_address_structured);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($creditNote->fresh(['items', 'originalStatutoryInvoice.eInvoiceRecord']));
        $this->assertTrue($payload->isSubmittable());
        $this->assertNotContains('missing_buyer_pin', $payload->gaps);
        $this->assertNotContains('missing_buyer_loc', $payload->gaps);
    }

    public function test_credit_note_mint_fails_closed_when_no_authoritative_structured_billing_exists(): void
    {
        $original = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-CN-FAIL-CLOSED',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'cn-fail-closed',
            'idempotency_key' => 'statutory:cn-fail-closed',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(10),
        ]);
        $this->createLineItem($original);
        $this->attachSubmittedIrn($original);

        try {
            $this->issueCreditNote($original);
            $this->fail('Expected credit note mint to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credit_note', $exception->errors());
        }

        $this->assertSame(0, StatutoryInvoice::query()
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->count());
        $this->assertSame(0, OutboxEvent::query()
            ->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)
            ->count());
    }

    public function test_complete_invoice_structured_billing_is_copied_unchanged_to_credit_note(): void
    {
        $structured = $this->delhiStructured();
        $original = $this->createCommerceOriginal([
            'billing_address_structured' => $structured,
        ]);
        $this->createCommerceOrder($original, $this->jharsugudaStructured());
        $this->attachSubmittedIrn($original);

        $creditNote = $this->issueCreditNote($original);

        $this->assertSame($structured, $creditNote->billing_address_structured);
    }

    public function test_partial_invoice_structured_falls_back_to_complete_commerce_source(): void
    {
        $original = $this->createCommerceOriginal([
            'billing_address_structured' => [
                'line1' => 'Partial only',
                'city' => 'Jharsuguda',
            ],
        ]);
        $commerceStructured = $this->jharsugudaStructured();
        $this->createCommerceOrder($original, $commerceStructured);
        $this->attachSubmittedIrn($original);

        $creditNote = $this->issueCreditNote($original);

        $this->assertSame($commerceStructured, $creditNote->billing_address_structured);
    }

    public function test_credit_note_idempotency_returns_same_credit_note(): void
    {
        $original = $this->createCommerceOriginal([
            'billing_address_structured' => $this->delhiStructured(),
        ]);
        $this->attachSubmittedIrn($original);
        $service = app(StatutoryInvoiceCreditNoteService::class);
        $key = $service->idempotencyKeyFor($original);

        $first = $service->issueForCancellation($original, $this->admin, 'First', $key);
        $second = $service->issueForCancellation($original->fresh(), $this->admin, 'Second', $key.'-retry');

        $this->assertFalse($first['idempotent'] ?? false);
        $this->assertTrue($second['idempotent'] ?? false);
        $this->assertSame($first['credit_note_id'], $second['credit_note_id']);
        $this->assertSame(1, StatutoryInvoice::query()
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->where('original_statutory_invoice_id', $original->id)
            ->count());
    }

    public function test_b2c_cancellation_still_does_not_issue_credit_note(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-CN-B2C',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'cn-b2c',
            'idempotency_key' => 'statutory:cn-b2c',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Retail Customer',
            'billing_address' => '1 Test Street, Delhi',
            'billing_address_structured' => $this->delhiStructured(),
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(10),
        ]);
        $this->createLineItem($invoice);

        $result = app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
            $invoice,
            $this->admin,
            'B2C cancel',
            StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
        );

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame('not_required', $result->creditNoteAction['status']);
        $this->assertSame(0, StatutoryInvoice::query()->where('document_type', StatutoryInvoiceDocumentType::CreditNote)->count());
    }

    public function test_credit_note_outbox_reaches_submitted_instead_of_skipped_for_commerce_fallback_billing(): void
    {
        $original = $this->createCommerceOriginal([
            'billing_address_structured' => null,
        ]);
        $this->createCommerceOrder($original, $this->jharsugudaStructured());
        $this->attachSubmittedIrn($original, irn: str_repeat('a', 64));

        app(StatutoryInvoiceCancellationOrchestrator::class)->cancel(
            invoice: $original->fresh(['eInvoiceRecord', 'items']),
            actor: $this->admin,
            reason: 'Beyond window CN IRN',
            idempotencyKey: StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$original->id,
        );

        $creditNote = StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $original->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->firstOrFail();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($creditNote->fresh(['items', 'originalStatutoryInvoice.eInvoiceRecord']));
        $this->assertTrue($payload->isSubmittable(), implode(', ', $payload->gaps));
        $queuedRecord = EInvoiceRecord::query()->where('invoice_id', $creditNote->id)->firstOrFail();
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $queuedRecord->status);

        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);
        $this->app->instance(
            EInvoiceGateway::class,
            FakeEInvoiceGateway::succeeding(str_repeat('c', 64)),
        );

        $outbox = OutboxEvent::query()
            ->where('aggregate_id', $creditNote->id)
            ->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)
            ->firstOrFail();
        app(EInvoiceProcessor::class)->process($outbox);

        $record = EInvoiceRecord::query()->where('invoice_id', $creditNote->id)->firstOrFail();
        $this->assertSame(
            EInvoiceRecordStatus::Submitted->value,
            $record->status,
            json_encode($record->response_payload, JSON_THROW_ON_ERROR),
        );
        $this->assertNotNull($record->irn);
    }

    private function issueCreditNote(StatutoryInvoice $original): StatutoryInvoice
    {
        $service = app(StatutoryInvoiceCreditNoteService::class);
        $result = $service->issueForCancellation(
            $original->fresh(['items']),
            $this->admin,
            'GST credit note adjustment',
            $service->idempotencyKeyFor($original),
        );

        return StatutoryInvoice::query()->findOrFail($result['credit_note_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCommerceOriginal(array $overrides = []): StatutoryInvoice
    {
        $original = StatutoryInvoice::query()->create(array_merge([
            'invoice_number' => 'INV-CN-COMMERCE-'.uniqid(),
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE-CN-'.uniqid(),
            'idempotency_key' => 'statutory:cn-commerce:'.uniqid(),
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'SANJIVANI HOSPITAL',
            'buyer_gstin' => '21AAOPA5417F1Z8',
            'billing_address' => 'Ekatali,Siria Bagicha, Jharsuguda, Odisha, 768201',
            'place_of_supply_state' => 'Odisha',
            'place_of_supply_state_code' => '21',
            'place_of_supply_source' => 'transaction_billing_address',
            'taxable_value' => 2880.51,
            'tax_total' => 518.49,
            'igst' => 518.49,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 3399.00,
            'issued_at' => Carbon::parse('2026-09-18 11:45:08'),
        ], $overrides));
        $this->createLineItem($original, [
            'taxable_value' => 2880.51,
            'tax_total' => 518.49,
            'igst' => 518.49,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'line_total' => 3399.00,
            'uqc' => 'NOS',
        ]);

        return $original->fresh(['items']) ?? $original;
    }

    private function createPosOriginal(InventorySale $sale): StatutoryInvoice
    {
        $original = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-CN-POS-'.uniqid(),
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale->value,
            'source_id' => (string) $sale->id,
            'inventory_sale_id' => $sale->id,
            'idempotency_key' => 'statutory:cn-pos:'.uniqid(),
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(10),
        ]);
        $this->createLineItem($original);

        return $original->fresh(['items', 'inventorySale']) ?? $original;
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function createCommerceOrder(StatutoryInvoice $original, array $structured): CommerceOrder
    {
        return CommerceOrder::query()->create([
            'order_no' => 'CO-'.$original->source_id,
            'channel' => $original->channel,
            'source_type' => 'commerce_order',
            'source_id' => $original->source_id,
            'source_order_id' => $original->source_id,
            'idempotency_key' => 'statutory:commerce-order:'.$original->id,
            'payload_hash' => hash('sha256', 'commerce-order-'.$original->id),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => $original->buyer_name,
            'buyer_gstin' => $original->buyer_gstin,
            'billing_address' => $original->billing_address,
            'billing_address_structured' => $structured,
            'place_of_supply_state' => $original->place_of_supply_state,
            'taxable_value' => $original->taxable_value,
            'tax_total' => $original->tax_total,
            'order_value' => $original->invoice_value,
            'statutory_invoice_id' => $original->id,
            'ordered_at' => $original->issued_at,
            'received_at' => $original->issued_at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLineItem(StatutoryInvoice $invoice, array $overrides = []): StatutoryInvoiceItem
    {
        return StatutoryInvoiceItem::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'description' => 'Hardware Item',
            'hsn_sac' => '84716050',
            'uqc' => 'NOS',
            'qty' => 1,
            'unit_price' => 100.00,
            'discount' => 0.00,
            'gst_percentage' => 18.00,
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'line_total' => 118.00,
        ], $overrides));
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice, string $irn = 'b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2'): void
    {
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'irn' => $irn,
            'ack_no' => 'ACK-ORIG',
            'ack_date' => Carbon::parse('2026-09-18 11:46:00'),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
    }

    /**
     * @return array{line1: string, city: string, state: string, pincode: string}
     */
    private function delhiStructured(): array
    {
        return [
            'line1' => '1 Test Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ];
    }

    /**
     * @return array{line1: string, city: string, state: string, pincode: string}
     */
    private function jharsugudaStructured(): array
    {
        return [
            'line1' => 'Ekatali,Siria Bagicha,Infront of Satsang Vihar,Near Railway Bridge, SH10, Jharsuguda, Odisha',
            'city' => 'Jharsuguda',
            'state' => 'Odisha',
            'pincode' => '768201',
        ];
    }

    private function inventoryBranch(): InventoryBranch
    {
        return InventoryBranch::query()->first() ?? InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
    }
}
