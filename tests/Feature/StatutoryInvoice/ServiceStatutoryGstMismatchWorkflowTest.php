<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryGstMismatchStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ServiceStatutoryGstMismatchException;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchDetector;
use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchExceptionService;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceStatutoryGstMismatchWorkflowTest extends TestCase
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
            'service_statutory_invoice.gst_mismatch.enabled' => true,
            'service_statutory_invoice.gst_mismatch.response_hours' => 72,
            'service_statutory_invoice.gst_mismatch.customer_email_enabled' => true,
            'mail.enabled' => true,
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

    public function test_detector_identifies_gstin_state_mismatch(): void
    {
        $commerce = $this->commerceOrder('RD3520001', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');

        $reason = app(ServiceStatutoryGstMismatchDetector::class)->detectForCommerceOrder($commerce);

        $this->assertSame(ServiceStatutoryGstMismatchDetector::REASON_BUYER_PIN_GSTIN_STATE_MISMATCH, $reason);
    }

    public function test_valid_b2b_order_has_no_gst_mismatch_detection(): void
    {
        $commerce = $this->commerceOrder('RD3520002', b2b: true, billingState: 'Uttar Pradesh', buyerGstin: '09AAJFV1437D1Z7');

        $this->assertNull(app(ServiceStatutoryGstMismatchDetector::class)->detectForCommerceOrder($commerce));
    }

    public function test_reconciliation_opens_exception_and_sends_customer_email_once(): void
    {
        Mail::fake();
        [$order] = $this->deskOrderWithCommerce('RD3520003', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-GST-003', 'completed_at' => now()]);
        $commerce = CommerceOrder::query()->where('source_id', 'RD3520003')->firstOrFail();
        $commerce->update(['customer_email' => 'customer@example.test']);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);

        $exception = ServiceStatutoryGstMismatchException::query()->where('commerce_order_id', $commerce->id)->first();
        $this->assertNotNull($exception);
        $this->assertNotNull($exception->customer_email_sent_at);
        $this->assertSame(ServiceStatutoryGstMismatchStatus::AwaitingCustomer, $exception->status);
        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RD3520003')->count());
        Mail::assertSentCount(1);
    }

    public function test_verified_correction_issues_b2b_invoice_and_preserves_original_gstin_snapshot(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3520004', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-GST-004', 'completed_at' => now()]);
        $commerce = CommerceOrder::query()->where('source_id', 'RD3520004')->firstOrFail();

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $exception = ServiceStatutoryGstMismatchException::query()->where('commerce_order_id', $commerce->id)->firstOrFail();

        app(ServiceStatutoryGstMismatchExceptionService::class)->recordVerifiedCorrection(
            $exception,
            [
                'buyer_gstin' => '09AAJFV1437D1Z7',
                'billing_state' => 'Uttar Pradesh',
                'place_of_supply_state' => 'Uttar Pradesh',
                'billing_address_structured' => [
                    'state' => 'Uttar Pradesh',
                    'city' => 'Lucknow',
                    'pincode' => '226001',
                ],
            ],
            $this->actor,
        );

        app(ServiceStatutoryGstMismatchExceptionService::class)->processDueExceptions();

        $exception->refresh();
        $this->assertSame('09AAJFV1437D1Z7', $exception->original_buyer_gstin);
        $this->assertSame('Delhi', $exception->original_billing_state);
        $this->assertSame(ServiceStatutoryGstMismatchStatus::B2bInvoiceIssued, $exception->status);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3520004')->count());
    }

    public function test_expired_exception_issues_b2c_fallback_and_preserves_original_gst_data(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3520005', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-GST-005', 'completed_at' => now()]);
        $commerce = CommerceOrder::query()->where('source_id', 'RD3520005')->firstOrFail();

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $exception = ServiceStatutoryGstMismatchException::query()->where('commerce_order_id', $commerce->id)->firstOrFail();
        $exception->update(['response_deadline_at' => now()->subMinute()]);

        app(ServiceStatutoryGstMismatchExceptionService::class)->processDueExceptions();

        $exception->refresh();
        $invoice = StatutoryInvoice::query()->where('source_id', 'RD3520005')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(ServiceStatutoryGstMismatchStatus::B2cInvoiceIssued, $exception->status);
        $this->assertSame('09AAJFV1437D1Z7', $exception->original_buyer_gstin);
        $this->assertStringContainsString('72-hour', (string) $exception->fallback_reason);
    }

    public function test_invalid_correction_does_not_issue_invoice(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3520006', b2b: true, billingState: 'Delhi', buyerGstin: '09AAJFV1437D1Z7');
        $order->update(['transaction_id' => 'TXN-GST-006', 'completed_at' => now()]);
        $commerce = CommerceOrder::query()->where('source_id', 'RD3520006')->firstOrFail();

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $exception = ServiceStatutoryGstMismatchException::query()->where('commerce_order_id', $commerce->id)->firstOrFail();

        $this->expectException(ValidationException::class);

        app(ServiceStatutoryGstMismatchExceptionService::class)->recordVerifiedCorrection(
            $exception,
            [
                'buyer_gstin' => '09AAJFV1437D1Z7',
                'billing_state' => 'Delhi',
                'place_of_supply_state' => 'Delhi',
            ],
            $this->actor,
        );
    }

    public function test_b2c_fallback_is_idempotent_when_invoice_already_exists(): void
    {
        [$order] = $this->deskOrderWithCommerce('RD3520007');
        $order->update(['transaction_id' => 'TXN-GST-007', 'completed_at' => now()]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3520007')->count());

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 5);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3520007')->count());
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
        $order = $this->deskOrder($orderId);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'GST mismatch workflow test',
            'description' => 'GST mismatch workflow test.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->commerceOrder($orderId, b2b: $b2b, billingState: $billingState, buyerGstin: $buyerGstin);

        return [$order->fresh()];
    }

    private function deskOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_'.$orderId,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function commerceOrder(
        string $sourceId,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::RdServiceIn,
        bool $b2b = false,
        ?string $billingState = 'Delhi',
        ?string $buyerGstin = null,
    ): CommerceOrder {
        $channelValue = $channel->value;
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => $channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:'.$channelValue.':commerce_order:'.$sourceId,
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
