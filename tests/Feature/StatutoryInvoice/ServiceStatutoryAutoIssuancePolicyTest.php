<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Incident;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceIssuanceCoordinator;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxWriter;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoicePermanentFailureException;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceStatutoryAutoIssuancePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $automationActor;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-30 12:00:00');

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
            'service_statutory_invoice.auto_issuance.enforce' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->automationActor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->automationActor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->superadmin = User::factory()->create(['is_active' => true, 'email' => 'owner@radium.local']);
        $this->superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rdservice_net_missing_commerce_denies_automatic_issuance_permanently(): void
    {
        Log::spy();

        $order = $this->deskOrder('RN600');
        app(ServiceStatutoryInvoiceIssuanceCoordinator::class)->attempt(
            $order,
            $this->automationActor,
            ServiceStatutoryInvoiceMintTrigger::InvoiceReconciliation,
        );

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $outbox = OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame(OutboxEventStatus::Failed, $outbox->status);
        $this->assertStringContainsString('no linked commerce order', (string) $outbox->last_error);

        Log::shouldHaveReceived('warning')
            ->with('service_statutory_invoice.auto_issuance_denied', \Mockery::on(function (array $context): bool {
                return ($context['classification'] ?? '') === 'auto_issuance_commerce_unlinked'
                    && ($context['order_id'] ?? '') === 'RN600';
            }));
    }

    public function test_rdservice_net_issue_from_support_order_with_null_trigger_is_denied(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN510');

        try {
            app(StatutoryInvoiceService::class)->issueFromSupportOrder($order, $this->automationActor);
            $this->fail('Expected permanent failure when minting RN510 without approval via null trigger.');
        } catch (ServiceStatutoryInvoicePermanentFailureException $exception) {
            $this->assertStringContainsString('blocked until explicit per-order approval', $exception->getMessage());
        }

        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RN510')->count());
    }

    public function test_rdservice_net_automatic_paths_blocked_without_approval(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN501');

        app(ServiceStatutoryInvoiceIssuanceCoordinator::class)->attempt(
            $order,
            $this->automationActor,
            ServiceStatutoryInvoiceMintTrigger::InvoiceReconciliation,
        );

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $outbox = OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame(OutboxEventStatus::Failed, $outbox->status);
        $this->assertStringContainsString('blocked until explicit per-order approval', (string) $outbox->last_error);
    }

    public function test_rdservice_net_reconciliation_blocked_without_approval(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN502');
        $order->update([
            'transaction_id' => 'TXN-RN502',
            'completed_at' => now(),
        ]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RN502')->count());
    }

    public function test_rdservice_net_outbox_retry_blocked_without_endless_retry(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN503');
        OutboxEvent::query()->create([
            'idempotency_key' => ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id),
            'event_type' => ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE,
            'aggregate_type' => ServiceStatutoryInvoiceMintOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => $order->id,
            'payload' => [
                'order_pk' => $order->id,
                'order_id' => $order->order_id,
                'trigger' => ServiceStatutoryInvoiceMintTrigger::InvoiceRetry->value,
            ],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(OutboxEventStatus::Failed, OutboxEvent::query()->first()->status);
        $this->assertSame(0, OutboxEvent::query()->where('status', OutboxEventStatus::Pending)->count());
    }

    public function test_approved_rdservice_net_automatic_mint_issues_exactly_one_invoice(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN504');
        $order->update([
            'transaction_id' => 'TXN-RN504',
            'completed_at' => now(),
        ]);

        $this->artisan('desk:approve-statutory-auto-issue', [
            'order_id' => 'RN504',
            '--actor' => (string) $this->superadmin->id,
        ])->assertSuccessful();

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);
        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RN504')->count());
        $this->assertSame(1, InvoiceSequenceAllocation::query()->count());
    }

    public function test_existing_statutory_invoice_is_no_op_without_retroactive_approval(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN508');
        $commerce = CommerceOrder::query()->where('source_id', 'RN508')->firstOrFail();

        app(StatutoryInvoiceService::class)->issueFromCommerceOrder($commerce, $this->automationActor);
        $this->assertNull($commerce->fresh()->statutory_auto_issue_approved_at);

        app(ServiceStatutoryInvoiceIssuanceCoordinator::class)->attempt(
            $order,
            $this->automationActor,
            ServiceStatutoryInvoiceMintTrigger::InvoiceReconciliation,
        );

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RN508')->count());
        $this->assertSame(0, OutboxEvent::query()
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->where('status', OutboxEventStatus::Failed)
            ->count());
    }

    public function test_finance_manual_issue_from_commerce_bypasses_auto_approval_gate(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN505');
        $commerce = CommerceOrder::query()->where('source_id', 'RN505')->firstOrFail();
        $order->update(['transaction_id' => 'TXN-RN505', 'completed_at' => now()]);

        app(StatutoryInvoiceService::class)->issueFromCommerceOrder($commerce, $this->automationActor);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RN505')->count());
        $this->assertNull($commerce->fresh()->statutory_auto_issue_approved_at);
    }

    public function test_finance_hub_issue_route_still_requires_invoice_issue_permission(): void
    {
        [$order] = $this->deskOrderWithNetCommerce('RN509');
        $commerce = CommerceOrder::query()->where('source_id', 'RN509')->firstOrFail();
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($viewer)
            ->post(route('finance.invoices.commerce-orders.issue', $commerce))
            ->assertForbidden();

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_rdservice_in_reference_assignment_still_auto_issues(): void
    {
        [$order] = $this->deskOrderWithInCommerce('RD3512599');

        app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-RD-AUTO',
            $this->automationActor,
            broadcast: false,
        );

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RD3512599')->count());
    }

    public function test_radiumbox_service_reference_assignment_still_auto_issues(): void
    {
        [$order] = $this->deskOrderWithBoxCommerce('RB3512600');

        app(OrderTransactionService::class)->assignTransactionId(
            $order,
            'TXN-RB-AUTO',
            $this->automationActor,
            broadcast: false,
        );

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RB3512600')->count());
    }

    public function test_hardware_commerce_is_not_minted_by_service_reconciliation(): void
    {
        $hardware = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE3519300',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE3519300',
            'source_order_id' => 'RDE3519300',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE3519300',
            'payload_hash' => hash('sha256', 'RDE3519300'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Hardware Buyer',
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'order_value' => 3049,
            'ordered_at' => '2026-09-08 10:00:00',
            'paid_at' => '2026-09-08 10:00:00',
            'received_at' => now(),
        ]);
        $hardware->items()->create([
            'line_no' => 1,
            'description' => 'MSO1300',
            'sku' => '951',
            'qty' => 1,
            'unit_price' => 3049,
            'hsn_sac' => '84716050',
            'gst_percentage' => 18,
            'taxable_value' => 2583.90,
            'tax_total' => 465.10,
            'line_total' => 3049,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 951,
        ]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(0, StatutoryInvoice::query()->where('source_id', 'RDE3519300')->count());
    }

    public function test_enforce_false_allows_rdservice_net_without_approval(): void
    {
        config(['service_statutory_invoice.auto_issuance.enforce' => false]);

        [$order] = $this->deskOrderWithNetCommerce('RN506');
        $order->update([
            'transaction_id' => 'TXN-RN506',
            'completed_at' => now(),
        ]);

        app(ServiceStatutoryInvoiceReconciliationService::class)->reconcile(limit: 50);

        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RN506')->count());
    }

    public function test_cli_refuses_non_superadmin_actor(): void
    {
        $this->deskOrderWithNetCommerce('RN507');

        $this->artisan('desk:approve-statutory-auto-issue', [
            'order_id' => 'RN507',
            '--actor' => (string) $this->automationActor->id,
        ])->assertFailed();
    }

    public function test_cli_approval_succeeds_and_repeat_is_idempotent(): void
    {
        $this->deskOrderWithNetCommerce('RN510');

        $this->artisan('desk:approve-statutory-auto-issue', [
            'order_id' => 'RN510',
            '--actor' => (string) $this->superadmin->id,
        ])->assertSuccessful();

        $commerce = CommerceOrder::query()->where('source_id', 'RN510')->firstOrFail();
        $firstAt = $commerce->statutory_auto_issue_approved_at;
        $firstBy = $commerce->statutory_auto_issue_approved_by;

        $this->artisan('desk:approve-statutory-auto-issue', [
            'order_id' => 'RN510',
            '--actor' => (string) $this->superadmin->id,
        ])->assertSuccessful();

        $commerce->refresh();
        $this->assertTrue($firstAt->equalTo($commerce->statutory_auto_issue_approved_at));
        $this->assertSame($firstBy, $commerce->statutory_auto_issue_approved_by);
    }

    public function test_channel_ingest_auto_issue_flag_cannot_mint_without_cutover(): void
    {
        config(['channel_ingest.auto_issue_invoice' => true]);

        $this->assertTrue((bool) config('channel_ingest.auto_issue_invoice'));
        $this->expectException(ValidationException::class);

        app(ChannelIngestService::class);
        $reflection = new \ReflectionClass(ChannelIngestService::class);
        $method = $reflection->getMethod('assertMustNotAutoMint');
        $method->setAccessible(true);
        $method->invoke(app(ChannelIngestService::class));
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function deskOrderWithNetCommerce(string $orderId): array
    {
        $order = $this->deskOrder($orderId);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Net auto issue gate',
            'description' => 'Net auto issue gate.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->automationActor->id,
            'updated_by' => $this->automationActor->id,
        ]);
        $this->netCommerceOrder($orderId);

        return [$order->fresh(), Incident::query()->where('order_id', $order->id)->firstOrFail()];
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function deskOrderWithInCommerce(string $orderId): array
    {
        $order = $this->deskOrder($orderId);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'In auto issue',
            'description' => 'In auto issue.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->automationActor->id,
            'updated_by' => $this->automationActor->id,
        ]);
        $this->inCommerceOrder($orderId);

        return [$order->fresh(), Incident::query()->where('order_id', $order->id)->firstOrFail()];
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function deskOrderWithBoxCommerce(string $orderId): array
    {
        $order = $this->deskOrder($orderId);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Box auto issue',
            'description' => 'Box auto issue.',
            'status' => IncidentStatus::Open,
            'created_by' => $this->automationActor->id,
            'updated_by' => $this->automationActor->id,
        ]);
        $this->boxCommerceOrder($orderId);

        return [$order->fresh(), Incident::query()->where('order_id', $order->id)->firstOrFail()];
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
            'created_by' => $this->automationActor->id,
            'updated_by' => $this->automationActor->id,
        ]);
    }

    private function netCommerceOrder(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_net:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 507.63,
            'tax_total' => 91.37,
            'order_value' => 599,
            'ordered_at' => '2026-10-29 18:59:41',
            'paid_at' => '2026-10-29 18:59:41',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998314',
            'qty' => 1,
            'unit_price' => 507.63,
            'gst_percentage' => 18,
            'taxable_value' => 507.63,
            'tax_total' => 91.37,
            'line_total' => 599,
        ]);

        return $order->fresh(['items']);
    }

    private function inCommerceOrder(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
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

        return $order->fresh(['items']);
    }

    private function boxCommerceOrder(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => '2026-09-06 10:00:00',
            'paid_at' => '2026-09-06 10:00:00',
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

        return $order->fresh(['items']);
    }
}
