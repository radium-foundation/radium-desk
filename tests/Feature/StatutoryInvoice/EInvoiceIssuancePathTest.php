<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIssuancePolicy;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EInvoiceIssuancePathTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 12:00:00');
        Storage::fake('local');
        Http::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::HardwareOnly->value,
            'channel_ingest.auto_issue_invoice' => false,
        ]);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_commerce_b2b_hardware_is_queued_in_phase_a_and_phase_b(): void
    {
        $phaseA = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RB-HW-A', StatutoryInvoiceChannel::RadiumBoxCom, '84716050', '07AAAAA0000A1Z5'),
            $this->actor,
        );
        $this->assertQueued($phaseA->id);

        $this->enablePhaseB();
        $phaseB = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RB-HW-B', StatutoryInvoiceChannel::RadiumBoxCom, '84716050', '07AAAAA0000A1Z5'),
            $this->actor,
        );
        $this->assertQueued($phaseB->id);
        Http::assertNothingSent();
    }

    public function test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b(): void
    {
        $phaseA = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-SVC-A', StatutoryInvoiceChannel::RdServiceIn, '998313', '07AAAAA0000A1Z5'),
            $this->actor,
        );
        $record = EInvoiceRecord::query()->where('invoice_id', $phaseA->id)->first();
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_SERVICE, $record?->response_payload['skip_reason'] ?? null);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());

        $this->enablePhaseB();
        $existingOrder = CommerceOrder::query()->where('source_id', 'RD-SVC-A')->firstOrFail();
        $this->invoices->issueFromCommerceOrder($existingOrder->fresh(), $this->actor);
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());

        $phaseB = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-SVC-B', StatutoryInvoiceChannel::RdServiceIn, '998313', '07AAAAA0000A1Z5'),
            $this->actor,
        );
        $this->assertQueued($phaseB->id);
        Http::assertNothingSent();
    }

    public function test_pos_b2b_hardware_is_queued_in_phase_a_and_phase_b(): void
    {
        $phaseA = $this->invoices->issueFromPosSale($this->posSale('HUB-A', '07AAAAA0000A1Z5'), $this->actor);
        $this->assertQueued($phaseA->id);

        $this->enablePhaseB();
        $phaseB = $this->invoices->issueFromPosSale($this->posSale('HUB-B', '07AAAAA0000A1Z5'), $this->actor);
        $this->assertQueued($phaseB->id);
        Http::assertNothingSent();
    }

    public function test_b2c_commerce_and_pos_are_blocked_in_both_policies(): void
    {
        $commerceA = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RB-B2C-A', StatutoryInvoiceChannel::RadiumBoxCom, '84716050', null),
            $this->actor,
        );
        $this->assertSame('b2c_not_eligible', EInvoiceRecord::query()->where('invoice_id', $commerceA->id)->value('response_payload')['skip_reason'] ?? null);

        $posA = $this->invoices->issueFromPosSale($this->posSale('HUB-B2C-A', null), $this->actor);
        $this->assertSame('b2c_not_eligible', EInvoiceRecord::query()->where('invoice_id', $posA->id)->value('response_payload')['skip_reason'] ?? null);

        $this->enablePhaseB();
        $commerceB = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD-B2C-B', StatutoryInvoiceChannel::RdServiceIn, '998313', null),
            $this->actor,
        );
        $this->assertSame('b2c_not_eligible', EInvoiceRecord::query()->where('invoice_id', $commerceB->id)->value('response_payload')['skip_reason'] ?? null);
        $posB = $this->invoices->issueFromPosSale($this->posSale('HUB-B2C-B', null), $this->actor);
        $this->assertSame('b2c_not_eligible', EInvoiceRecord::query()->where('invoice_id', $posB->id)->value('response_payload')['skip_reason'] ?? null);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        Http::assertNothingSent();
    }

    private function enablePhaseB(): void
    {
        config(['statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value]);
        $this->app->forgetInstance(EInvoiceIssuancePolicy::class);
        $this->app->forgetInstance(EInvoiceEligibility::class);
        $this->app->forgetInstance(StatutoryInvoiceService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
    }

    private function assertQueued(int $invoiceId): void
    {
        $record = EInvoiceRecord::query()->where('invoice_id', $invoiceId)->first();
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record?->status);
        $this->assertSame('b2b_eligible', $record?->response_payload['queue_reason'] ?? null);
        $this->assertNotNull(OutboxEvent::query()
            ->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice(
                StatutoryInvoice::query()->findOrFail($invoiceId)
            ))
            ->first());
    }

    private function commerceOrder(
        string $sourceId,
        StatutoryInvoiceChannel $channel,
        string $hsnSac,
        ?string $buyerGstin,
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channel->value.':commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer Industries',
            'buyer_gstin' => $buyerGstin,
            'billing_state' => 'Maharashtra',
            'billing_address' => '1 Test Street, Mumbai',
            'branch_code' => 'MUMBAI',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'sku' => $channel === StatutoryInvoiceChannel::RadiumBoxCom ? 'RBMFS110L1' : 'RD-SVC',
            'description' => $channel === StatutoryInvoiceChannel::RadiumBoxCom ? 'Mantra MFS 110' : 'RD Service',
            'hsn_sac' => $hsnSac,
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }

    private function posSale(string $serial, ?string $buyerGstin)
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'Delhi Retail', 'is_active' => true],
        );
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$serial,
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'uqc' => 'PCS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, [$serial], $this->actor);

        return app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000099'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => $buyerGstin,
                'place_of_supply_state' => 'Delhi',
                'billing_address' => '1 Test Street, Delhi',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
            ],
        );
    }
}
