<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\BusinessHoldType;
use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\BusinessHold;
use App\Models\CommercialServiceRestoration;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\Commercial\CommercialStateResolver;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RefundCaseCloseService;
use App\Services\ServiceCaseStatusService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundHoldLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);
        config([
            'commercial_state.enabled' => true,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        ]);
    }

    public function test_refund_completion_clears_hold_when_linked_case_is_already_closed(): void
    {
        [$admin, $ops, $order, $incident, $refund] = $this->closedCaseRefundFixture('RD-BH-PRECLOSED');

        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh(), BusinessHoldType::Refund));

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-PRECLOSED',
                'execution_transaction_id' => 'TXN-PRECLOSED',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $incident->refresh();

        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertNotNull(BusinessHold::query()->where('incident_id', $incident->id)->value('cleared_at'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'business_hold.cleared',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_refund_completion_finalize_is_idempotent_on_retry(): void
    {
        [$admin, $ops, $order, $incident, $refund] = $this->closedCaseRefundFixture('RD-BH-RETRY');

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-RETRY',
                'execution_transaction_id' => 'TXN-RETRY',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);

        $clearedAuditCount = AuditLog::query()
            ->where('event', 'business_hold.cleared')
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->count();

        app(RefundCaseCloseService::class)->closeLinkedCase($refund->fresh(), $ops);
        app(RefundCaseCloseService::class)->closeLinkedCase($refund->fresh(), $ops);

        $this->assertSame($clearedAuditCount, AuditLog::query()
            ->where('event', 'business_hold.cleared')
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->count());
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));
        $this->assertSame(RefundStatus::Closed, $refund->fresh()->status);
    }

    public function test_revoke_clears_stale_refund_hold_after_commercial_restoration(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 9101,
                    'wallet_reversal_reference' => '9101',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund, $order, $incident] = $this->completedWalletRefundWithStaleHold(
            orderNumber: 'RD3148',
            reference: 'REF-2026-000304',
        );

        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh(), BusinessHoldType::Refund));

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Customer wants service instead of wallet refund.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $incident->refresh();

        $this->assertSame(RefundStatus::Revoked, $refund->status);
        $this->assertSame(CommercialState::ServiceRestored, app(CommercialStateResolver::class)->forIncident($incident)->state);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'business_hold.cleared',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_revoke_retry_does_not_duplicate_restoration_or_hold_clear(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 200,
                'data' => [
                    'wallet_reversal_transaction_id' => 9102,
                    'wallet_reversal_reference' => '9102',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 200),
        ]);

        [$admin, $refund, , $incident] = $this->completedWalletRefundWithStaleHold(
            orderNumber: 'RD3149',
            reference: 'REF-2026-000305',
        );

        $payload = [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer changed mind.',
        ];

        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload);
        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload)
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertSentCount(1);
        $this->assertSame(1, CommercialServiceRestoration::query()->where('refund_request_id', $refund->id)->count());
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));
    }

    public function test_clear_refund_hold_targets_only_matching_refund_request(): void
    {
        [$admin, $ops, $order, $incident, $refund] = $this->closedCaseRefundFixture('RD-BH-TARGET');

        $otherRefund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-OTHER-'.uniqid(),
            'amount' => '100.00',
            'refund_amount' => '100.00',
            'reason' => 'Different refund request.',
            'status' => RefundStatus::Rejected,
            'requested_by' => $admin->id,
        ]);

        app(BusinessHoldService::class)->clearRefundHoldForRefund($otherRefund, $admin, 'test_other_refund');

        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh(), BusinessHoldType::Refund));

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-TARGET',
                'execution_transaction_id' => 'TXN-TARGET',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));
    }

    public function test_normal_open_case_refund_completion_still_clears_hold_and_closes_case(): void
    {
        $ops = $this->operationsAdmin();
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        [$order, $incident] = $this->readyOrderAndIncident('RD-BH-NORMAL', $admin, $agent);

        $refund = $this->submitRefund($agent, $order, $incident);
        $this->approveRefund($admin, $refund);
        $refund->update(['communication_channels' => []]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-NORMAL',
                'execution_transaction_id' => 'TXN-NORMAL',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $incident->refresh();
        $refund->refresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertContains($refund->status, [RefundStatus::Completed, RefundStatus::Closed]);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
    }

    public function test_reject_still_clears_refund_hold(): void
    {
        $admin = $this->adminUser();
        $agent = $this->agentUser();
        [$order, $incident] = $this->readyOrderAndIncident('RD-BH-REJECT2', $admin, $agent);

        $refund = $this->submitRefund($agent, $order, $incident);
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));

        $this->actingAs($admin)
            ->post(route('refunds.reject', $refund), [
                'review_notes' => 'Refund not eligible.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident->fresh()));
        $this->assertSame(RefundStatus::Rejected, $refund->fresh()->status);
    }

    public function test_reopened_case_after_prec_closed_refund_completion_does_not_block_service_reference(): void
    {
        $admin = $this->adminUser();
        $ops = $this->operationsAdmin();
        $agent = $this->agentUser();
        [$order, $incident] = $this->readyOrderAndIncident('RD2840-TEST', $admin, $agent);

        app(ServiceCaseStatusService::class)->updateStatus($incident, IncidentStatus::Closed, $admin);
        $incident->refresh();
        $this->assertSame(IncidentStatus::Closed, $incident->status);

        $refund = $this->submitRefund($agent, $order, $incident);
        $this->approveRefund($admin, $refund);
        $refund->update(['communication_channels' => []]);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => '2571',
                'execution_transaction_id' => '2571',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        app(ServiceCaseStatusService::class)->reopen($incident->fresh(), $admin);
        $incident->refresh();

        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));

        app(BusinessHoldService::class)->assertOperationsAllowed($incident, 'assigned a service reference');

        $commercialReason = app(CommercialStateResolver::class)->ineligibilityReason(
            $incident,
            CommercialAction::AssignServiceReference,
        );
        $this->assertNotNull($commercialReason);
        $this->assertStringNotContainsString('Refund Hold', $commercialReason);
    }

    /**
     * @return array{0: User, 1: User, 2: Order, 3: Incident, 4: RefundRequest}
     */
    private function closedCaseRefundFixture(string $orderId): array
    {
        $admin = $this->adminUser();
        $ops = $this->operationsAdmin();
        $agent = $this->agentUser();
        [$order, $incident] = $this->readyOrderAndIncident($orderId, $admin, $agent);

        app(ServiceCaseStatusService::class)->updateStatus($incident, IncidentStatus::Closed, $admin);

        $refund = $this->submitRefund($agent, $order, $incident);
        $this->approveRefund($admin, $refund, ApprovedRefundMethod::BankTransfer);
        $refund->update(['communication_channels' => []]);

        return [$admin, $ops, $order, $incident, $refund];
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: Order, 3: Incident}
     */
    private function completedWalletRefundWithStaleHold(
        string $orderNumber,
        string $reference,
    ): array {
        $admin = $this->operationsAdmin();

        $order = Order::query()->create([
            'order_id' => $orderNumber,
            'serial_number' => 'SN-'.$orderNumber,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => '499.00',
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-'.substr($reference, -5),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Stale hold revoke test',
            'description' => 'Stale hold revoke test incident.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => '499.00',
            'refund_amount' => '499.00',
            'reason' => 'Wallet refund revoke test.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(2),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2),
            'execution_reference_no' => '2571',
            'execution_transaction_id' => '2571',
            'communication_channels' => [],
        ]);

        app(BusinessHoldService::class)->activateRefundHold($incident->fresh(), $refund, $admin);

        return [$admin, $refund, $order, $incident];
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function readyOrderAndIncident(string $orderId, User $admin, User $agent): array
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'created_by' => $admin->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$orderId}",
            'description' => "Case {$orderId}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        return [$order, $incident];
    }

    private function submitRefund(User $agent, Order $order, Incident $incident): RefundRequest
    {
        $this->actingAs($agent)->post(route('refunds.store'), [
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'amount' => 1000,
            'reason' => 'Customer requested cancellation and full refund.',
            'remarks' => 'Customer confirmed refund request through support channel.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm->value,
        ])->assertRedirect();

        return RefundRequest::query()
            ->where('incident_id', $incident->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function approveRefund(
        User $admin,
        RefundRequest $refund,
        ApprovedRefundMethod $method = ApprovedRefundMethod::BankTransfer,
    ): void {
        $this->actingAs($admin)
            ->post(route('refunds.approve', $refund), [
                'approved_refund_method' => $method->value,
                'deduction_profile_key' => 'full_refund',
                'refund_amount' => $refund->refund_amount ?? $refund->amount,
                'partial_difference_reason' => 'partial_refund',
                'review_notes' => 'Approved.',
            ])
            ->assertRedirect(route('refunds.show', $refund));
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function operationsAdmin(): User
    {
        $ops = User::factory()->create();
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $ops;
    }

    private function agentUser(): User
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }
}
