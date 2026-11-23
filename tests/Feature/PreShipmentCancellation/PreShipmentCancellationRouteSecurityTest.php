<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreShipmentCancellationRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.central',
            'preship_cancellations.review',
            'preship_cancellations.approve',
            'preship_cancellations.reject',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function orderWithCase(): array
    {
        $authorized = User::factory()->create();
        $authorized->givePermissionTo([
            'preship_cancellations.submit',
            'preship_cancellations.view',
            'preship_cancellations.central',
            'preship_cancellations.approve',
            'preship_cancellations.reject',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RBP9200100',
            'product_name' => 'Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_sec',
            'created_by' => $authorized->id,
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-SEC',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $order->order_id,
            'idempotency_key' => 'psc-sec',
            'payload_hash' => 'hash',
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => 1000,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $case = PreShipmentCancellationRequest::query()->create([
            'reference_no' => 'PSC-SEC-0001',
            'order_id' => $order->id,
            'source_id' => $order->order_id,
            'order_family' => 'rbp',
            'status' => PreShipmentCancellationStatus::FinanceReview,
            'cancellation_reason' => 'Security route test case',
            'submitted_by_user_id' => $authorized->id,
            'order_total_amount' => 1000,
            'wallet_tender_amount' => 0,
            'external_tender_amount' => 1000,
            'already_refunded_amount' => 0,
            'maximum_refundable_amount' => 1000,
            'requested_refund_amount' => 1000,
            'split_tender' => false,
            'execution_hold' => false,
            'idempotency_key' => 'psc-sec-case',
            'committed_at' => now(),
        ]);

        return [$authorized, $order, $case];
    }

    public function test_all_get_routes_return_404_when_feature_disabled(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        [$authorized, $order, $case] = $this->orderWithCase();

        $this->actingAs($authorized)->get(route('orders.pre-shipment-cancellations.create', $order))->assertNotFound();
        $this->actingAs($authorized)->get(route('pre-shipment-cancellations.show', $case))->assertNotFound();
        $this->actingAs($authorized)->get(route('pre-shipment-cancellations.central-queue'))->assertNotFound();
    }

    public function test_all_post_routes_return_403_when_feature_disabled(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        [$authorized, $order, $case] = $this->orderWithCase();

        $this->actingAs($authorized)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Should be blocked when flag is off.',
        ])->assertForbidden();

        $this->actingAs($authorized)->post(route('pre-shipment-cancellations.finance-approve', $case))->assertForbidden();
        $this->actingAs($authorized)->post(route('pre-shipment-cancellations.finance-reject', $case), [
            'rejection_reason' => 'blocked',
        ])->assertForbidden();
        $this->actingAs($authorized)->post(route('pre-shipment-cancellations.link-refund', $case))->assertForbidden();
        $this->actingAs($authorized)->post(route('pre-shipment-cancellations.retry-incident', $case))->assertForbidden();
    }

    public function test_unauthenticated_requests_are_redirected_or_forbidden(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        [, $order, $case] = $this->orderWithCase();

        $this->get(route('orders.pre-shipment-cancellations.create', $order))->assertRedirect();
        $this->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Guest submit attempt blocked.',
        ])->assertRedirect();
        $this->get(route('pre-shipment-cancellations.show', $case))->assertRedirect();
        $this->get(route('pre-shipment-cancellations.central-queue'))->assertRedirect();
    }

    public function test_forbidden_without_preship_permissions(): void
    {
        config(['pre_shipment_cancellation.enabled' => true]);
        $outsider = User::factory()->create();
        [, $order, $case] = $this->orderWithCase();

        $this->actingAs($outsider)->get(route('orders.pre-shipment-cancellations.create', $order))->assertForbidden();
        $this->actingAs($outsider)->post(route('orders.pre-shipment-cancellations.store', $order), [
            'cancellation_reason' => 'Outsider submit blocked by policy.',
        ])->assertForbidden();
        $this->actingAs($outsider)->get(route('pre-shipment-cancellations.show', $case))->assertForbidden();
        $this->actingAs($outsider)->get(route('pre-shipment-cancellations.central-queue'))->assertForbidden();
        $this->actingAs($outsider)->post(route('pre-shipment-cancellations.finance-approve', $case))->assertForbidden();
        $this->actingAs($outsider)->post(route('pre-shipment-cancellations.finance-reject', $case), [
            'rejection_reason' => 'blocked',
        ])->assertForbidden();
        $this->actingAs($outsider)->post(route('pre-shipment-cancellations.link-refund', $case))->assertForbidden();
        $this->actingAs($outsider)->post(route('pre-shipment-cancellations.retry-incident', $case))->assertForbidden();
    }
}
