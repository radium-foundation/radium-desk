<?php

namespace Tests\Feature\Refunds;

use App\Enums\CommerceOrderStatus;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\HardwareOrderRefundCaseGate;
use App\Services\RefundRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareOrderRefundCaseGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    private function agentWithOrder(string $orderId = 'RD2000099', ?float $paymentAmount = 1500): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-HW-'.random_int(1000, 9999),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Refund related incident',
            'description' => 'Incident for gate test.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        return [$agent, $order, $incident];
    }

    public function test_service_order_refund_via_general_form_still_allowed(): void
    {
        [$agent, $order, $incident] = $this->agentWithOrder('RD2000099');

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'amount' => 1500,
                'reason' => 'Customer returned device within warranty period.',
                'remarks' => 'Service order refund.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ])
            ->assertRedirect();

        $this->assertSame(1, RefundRequest::query()->count());
    }

    public function test_hardware_rde_order_blocked_on_direct_store(): void
    {
        [$agent, $order, $incident] = $this->agentWithOrder('RDE318801');

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'amount' => 500,
                'reason' => 'Customer returned device within warranty period.',
                'remarks' => 'Attempt bypass general form.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_fulfilment_linked_order_blocked_without_case_bypass(): void
    {
        [$agent, $order, $incident] = $this->agentWithOrder('RB-EC-'.random_int(10000, 99999));

        $branch = InventoryBranch::query()->create([
            'code' => 'BR-GATE',
            'name' => 'Gate Branch',
            'is_active' => true,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$order->order_id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => 'ec-'.$order->id,
            'payload_hash' => hash('sha256', (string) $order->id),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $branch->id,
            'ingested_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'amount' => 500,
                'reason' => 'Customer returned device within warranty period.',
                'remarks' => 'Fulfilment-linked hardware.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_http_post_cannot_supply_internal_gate_bypass(): void
    {
        [$agent, $order, $incident] = $this->agentWithOrder('RDE318802');

        $this->actingAs($agent)
            ->post(route('refunds.store'), [
                'order_id' => $order->id,
                'incident_id' => $incident->id,
                'amount' => 500,
                'reason' => 'Customer returned device within warranty period.',
                'remarks' => 'Forged bypass field.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
                'hardware_refund_gate_bypass' => HardwareOrderRefundCaseGate::BYPASS_RETURN_EXCHANGE_CASE_ONLINE,
            ])
            ->assertSessionHasErrors('order_id');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_internal_bypass_allows_refund_request_service_create(): void
    {
        [$agent, $order] = $this->agentWithOrder('RDE318803');

        $refund = app(RefundRequestService::class)->create($agent, [
            'order_id' => $order->id,
            'amount' => 1500,
            'reason' => 'Customer returned device within warranty period.',
            'remarks' => 'Case workflow.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
            'hardware_refund_gate_bypass' => HardwareOrderRefundCaseGate::BYPASS_RETURN_EXCHANGE_CASE_ONLINE,
        ], request());

        $this->assertInstanceOf(RefundRequest::class, $refund);
        $this->assertSame($order->id, $refund->order_id);
    }

    public function test_gate_rejects_hardware_without_bypass(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RDE318804',
            'status' => 'active',
            'payment_amount' => 1000,
            'created_by' => User::factory()->create()->id,
        ]);

        $gate = app(HardwareOrderRefundCaseGate::class);

        $this->expectException(ValidationException::class);
        $gate->assertMayCreateRefund($order, []);
    }

    public function test_create_page_flags_hardware_order_and_disables_submit(): void
    {
        [$agent, $order] = $this->agentWithOrder('RDE318805');

        $response = $this->actingAs($agent)
            ->get(route('refunds.create', ['order' => $order->id]));

        $response->assertOk();
        $response->assertSee('controlled case workflow required', false);
        $response->assertSee('disabled', false);
    }
}
