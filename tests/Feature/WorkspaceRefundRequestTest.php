<?php

namespace Tests\Feature;

use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360OverflowMenuPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceRefundRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_overflow_menu_exposes_refund_workspace_trigger(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $agent,
            $order,
        );

        $caseItems = collect($menu['groups'])->firstWhere('label', 'Case')['items'];
        $refund = collect($caseItems)->firstWhere('id', 'refund');

        $this->assertNotNull($refund);
        $this->assertSame('trigger', $refund['type']);
        $this->assertSame('refund-request', $refund['trigger']);
        $this->assertTrue((bool) ($refund['destructive'] ?? false));
        $this->assertFalse(collect($menu['groups'])->contains('label', 'Finance'));
    }

    public function test_authorized_user_receives_refund_request_component_fragment(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, paymentAmount: 2500);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'refund-request',
                'context' => 'customer',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Refund Request', false)
            ->assertSee($order->order_id, false)
            ->assertSee($incident->reference_no, false)
            ->assertSee('₹2,500.00', false)
            ->assertSee('Payment Reference', false)
            ->assertSee('TXN-12345', false)
            ->assertSee('id="refund-request-amount"', false)
            ->assertSee('value="2500.00"', false)
            ->assertSee('data-workspace-action-form="refund-request"', false)
            ->assertDontSee('Reference Number', false)
            ->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_refund_amount_defaults_to_maximum_refundable_after_prior_refunds(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, paymentAmount: 2499);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000099',
            'amount' => 500,
            'refund_amount' => 500,
            'reason' => 'Prior partial refund for testing maximum refundable default.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'refund-request',
                'context' => 'customer',
            ]))
            ->assertOk()
            ->assertSee('₹1,999.00', false)
            ->assertSee('value="1999.00"', false);
    }

    public function test_workspace_refund_request_creates_refund_and_refreshes_customer360(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, paymentAmount: 1800);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.refund-request', $incident), [
                'amount' => 1800,
                'reason' => 'Customer returned device within warranty period.',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
                'notify_email' => '1',
                'notify_whatsapp' => '1',
                'workspace_context' => 'customer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'refund-request')
            ->assertJsonPath('ui.close_workspace_host', true)
            ->assertJsonPath('extensions.refresh_customer360', true);

        $refund = RefundRequest::query()->first();

        $this->assertNotNull($refund);
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($incident->id, $refund->incident_id);
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(1800.0, (float) $refund->amount);
    }

    public function test_workspace_refund_request_validation_errors_rerender_fragment(): void
    {
        [$agent, $incident] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, paymentAmount: 1800);

        $response = $this->actingAs($agent)
            ->postJson(route('incidents.workspace.refund-request', $incident), [
                'amount' => 1800,
                'reason' => 'short',
                'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
                'workspace_context' => 'customer',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('ui.close_workspace_host', false);

        $fragment = $response->json('refresh.fragments.0.html');

        $this->assertIsString($fragment);
        $this->assertStringContainsString('data-workspace-action-form="refund-request"', $fragment);
        $this->assertStringContainsString('Refund Request', $fragment);
        $this->assertStringContainsString('short', $fragment);
        $this->assertDatabaseCount('refund_requests', 0);
    }

    public function test_user_without_refund_permission_cannot_load_component(): void
    {
        [$agent, $incident] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT);
        $hardware = User::factory()->create();
        $hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->actingAs($hardware)
            ->get(route('incidents.components.show', [
                'incident' => $incident,
                'component' => 'refund-request',
                'context' => 'customer',
            ]))
            ->assertForbidden();
    }

    public function test_standalone_refunds_create_page_remains_available(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('refunds.create'))
            ->assertOk()
            ->assertSee('Create Refund Request', false);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(string $role, ?float $paymentAmount = 1500): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-REFUND-'.uniqid(),
            'serial_number' => 'SN-WS-REFUND',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_name' => 'Refund Workspace Customer',
            'payment_amount' => $paymentAmount,
            'payment_method' => 'UPI',
            'payment_date' => now(),
            'transaction_id' => 'TXN-12345',
            'cashfree_payment_id' => 'cf_pay_123',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Hardware',
            'source' => IncidentSource::Call,
            'title' => 'Workspace refund case',
            'description' => 'Workspace refund case description.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        return [$user, $incident, $order];
    }
}
