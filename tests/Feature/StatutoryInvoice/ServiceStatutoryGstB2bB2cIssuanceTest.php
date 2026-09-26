<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Customer360\Customer360StatutoryInvoicePresenter;
use App\Services\IncidentReferenceService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceStatutoryGstB2bB2cIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 18:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'channel_ingest.auto_issue_invoice' => false,
            'service_statutory_invoice.mint_retry.enabled' => true,
            'service_statutory_invoice.reconciliation.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_valid_b2b_service_order_issues_b2b_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521001', b2b: true, billingState: 'Uttar Pradesh', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-B2B-OK', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3521001')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('09AAJFV1437D1Z7', $invoice->buyer_gstin);
    }

    public function test_valid_b2c_service_order_without_gstin_issues_b2c_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521002');
        $order->update(['transaction_id' => 'TXN-B2C-OK', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3521002')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);
    }

    public function test_gstin_state_mismatch_issues_b2c_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521003', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-MISMATCH', 'completed_at' => now()]);
        $commerce = CommerceOrder::query()->where('source_id', 'RD3521003')->firstOrFail();

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($commerce);
        $this->assertFalse($decision->issueAsB2b);
        $this->assertSame('gstin_state_mismatch', $decision->b2cDowngradeCode);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3521003')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('09AAJFV1437D1Z7', $commerce->fresh()->buyer_gstin);
    }

    public function test_invalid_gstin_issues_b2c_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521004', b2b: true, billingState: 'Delhi', buyerGstin: '27-NOT-A-GSTIN');
        $order->update(['transaction_id' => 'TXN-INVALID', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3521004')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);
    }

    public function test_customer_360_shows_b2c_downgrade_note_for_state_mismatch(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521005', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $incident = Incident::query()->where('order_id', $order->id)->firstOrFail();
        $order->update(['transaction_id' => 'TXN-C360', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $invoices = app(Customer360StatutoryInvoicePresenter::class)->forIncident($incident, $this->actor);
        $this->assertCount(1, $invoices);
        $this->assertSame('GSTIN/state mismatch — B2C invoice issued.', $invoices[0]['service_b2c_note']);
    }

    public function test_repeated_reconciliation_does_not_create_duplicate_invoices(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3521006', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-NO-DUP', 'completed_at' => now()]);

        $service = app(ServiceStatutoryInvoiceReconciliationService::class);
        $service->reconcile(limit: 5);
        $service->reconcile(limit: 5);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3521006')->count());
    }

    /**
     * @return array{0: Order}
     */
    private function deskOrderWithCommerce(
        string $orderId,
        bool $b2b = false,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): array {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'GST B2B/B2C issuance test',
            'description' => 'GST B2B/B2C issuance test.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder($orderId, $b2b, $billingState, $buyerGstin);

        return [$order->fresh()];
    }

    private function commerceOrder(
        string $sourceId,
        bool $b2b = false,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rd_service_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'customer_email' => 'customer@example.test',
            'buyer_gstin' => $b2b ? $buyerGstin : null,
            'billing_state' => $billingState,
            'billing_address_structured' => $b2b ? [
                'state' => $billingState,
                'city' => $billingState === 'Uttar Pradesh' ? 'Lucknow' : 'New Delhi',
                'pincode' => $billingState === 'Uttar Pradesh' ? '226001' : '110001',
            ] : null,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $billingState,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => '2026-09-06 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);

        return $order;
    }
}
