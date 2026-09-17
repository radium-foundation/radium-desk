<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CommercialState;
use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\CommercialServiceRestoration;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundRevocationAttempt;
use App\Models\User;
use App\Services\Commercial\CommercialStateResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundRevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['commercial_state.enabled' => true]);
        config([
            'rdservice_in.wallet_refund_reversal_enabled' => true,
            'order_lookup.spokes.rdservice_in.enabled' => true,
            'order_lookup.spokes.rdservice_in.base_url' => 'https://rdservice.in.test',
            'order_lookup.spokes.rdservice_in.token' => 'desk-rdservice-wallet-token',
        ]);
    }

    public function test_completed_wallet_refund_can_be_revoked_for_wants_service(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'message' => 'Wallet debit created',
                'data' => [
                    'wallet_reversal_transaction_id' => 9001,
                    'wallet_reversal_reference' => '9001',
                    'desk_refund_reference' => 'REF-2026-000296',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund, $order, $incident] = $this->completedWalletRefundFixture(
            orderNumber: 'RD3147',
            amount: '499.00',
            reference: 'REF-2026-000296',
            walletTransactionId: '2559',
        );

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Customer wants service instead of wallet refund.',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-revoked');

        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
        $this->assertSame('9001', $refund->revoke_wallet_reversal_transaction_id);
        $this->assertSame('9001', $refund->revoke_wallet_reversal_reference);
        $this->assertSame(RefundRevokeCustomerOutcome::WantsService, $refund->revoke_customer_outcome);
        $this->assertSame('2559', $refund->execution_transaction_id);

        $restoration = CommercialServiceRestoration::query()->where('refund_request_id', $refund->id)->first();
        $this->assertNotNull($restoration);
        $this->assertSame('9001', $restoration->wallet_reversal_reference);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());
        $this->assertSame(CommercialState::ServiceRestored, $snapshot->state);
        $this->assertFalse($snapshot->blocks(\App\Enums\CommercialAction::AssignServiceReference));
    }

    public function test_revoke_requires_reason_and_customer_outcome(): void
    {
        [$admin, $refund] = $this->completedWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [])
            ->assertSessionHasErrors(['customer_outcome', 'revoke_reason']);
    }

    public function test_original_payment_method_outcome_is_rejected(): void
    {
        [$admin, $refund] = $this->completedWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsOriginalPaymentMethod->value,
                'revoke_reason' => 'Customer wants bank refund.',
            ])
            ->assertSessionHasErrors('customer_outcome');
    }

    public function test_wallet_reversal_is_idempotent_on_duplicate_revoke(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 200,
                'message' => 'Wallet debit already exists',
                'data' => [
                    'wallet_reversal_transaction_id' => 9002,
                    'wallet_reversal_reference' => '9002',
                    'desk_refund_reference' => 'REF-2026-009930',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 200),
        ]);

        [$admin, $refund] = $this->completedWalletRefundFixture(reference: 'REF-2026-009930');

        $payload = [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer changed mind.',
        ];

        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload);
        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload)
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertSentCount(1);
        $this->assertSame(1, RefundRevocationAttempt::query()->where('refund_request_id', $refund->id)->count());
    }

    public function test_already_revoked_refund_is_idempotent(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 9003,
                    'wallet_reversal_reference' => '9003',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund] = $this->completedWalletRefundFixture();

        $payload = [
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
            'revoke_reason' => 'Customer wants service.',
        ];

        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload);
        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);

        $this->actingAs($admin)->post(route('refunds.revoke', $refund), $payload)
            ->assertRedirect(route('refunds.show', $refund));

        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
    }

    public function test_ineligible_refund_is_rejected(): void
    {
        [$admin, $refund] = $this->completedWalletRefundFixture();
        $refund->update(['status' => RefundStatus::PendingExecution]);

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Too early.',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_unauthorized_user_is_rejected(): void
    {
        [, $refund] = $this->completedWalletRefundFixture();
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Not allowed.',
            ])
            ->assertForbidden();
    }

    public function test_wallet_reversal_failure_does_not_revoke_refund_or_restore_service(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 422,
                'message' => 'Wallet credit not found',
            ], 422),
        ]);

        [$admin, $refund, , $incident] = $this->completedWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Should fail.',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertNull($refund->revoked_at);
        $this->assertSame(CommercialState::RefundCompleted, app(CommercialStateResolver::class)->forIncident($incident->fresh())->state);

        $attempt = RefundRevocationAttempt::query()->where('refund_request_id', $refund->id)->first();
        $this->assertSame(RefundRevocationAttemptStatus::Failed, $attempt?->status);
    }

    public function test_wallet_reversal_not_configured_fails_closed(): void
    {
        config(['rdservice_in.wallet_refund_reversal_enabled' => false]);

        [$admin, $refund] = $this->completedWalletRefundFixture();

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Should fail closed.',
            ])
            ->assertSessionHasErrors('refund');

        $refund->refresh();
        $this->assertSame(RefundStatus::Closed, $refund->status);
    }

    public function test_desk_failure_after_wallet_reversal_can_be_retried(): void
    {
        Http::fake([
            'https://rdservice.in.test/api/integrations/v1/wallet-refund-reversals' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_reversal_transaction_id' => 9010,
                    'wallet_reversal_reference' => '9010',
                    'debit' => '499.00',
                    'balance' => '0.00',
                ],
            ], 201),
        ]);

        [$admin, $refund] = $this->completedWalletRefundFixture();

        RefundRevocationAttempt::query()->create([
            'refund_request_id' => $refund->id,
            'idempotency_key' => 'refund-revoke:'.$refund->id,
            'customer_outcome' => RefundRevokeCustomerOutcome::WantsService,
            'revoke_reason' => 'Prior attempt',
            'status' => RefundRevocationAttemptStatus::WalletReversed,
            'wallet_reversal_reference' => '9010',
            'wallet_reversal_transaction_id' => '9010',
            'actor_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('refunds.revoke', $refund), [
                'customer_outcome' => RefundRevokeCustomerOutcome::WantsService->value,
                'revoke_reason' => 'Retry after wallet reversal.',
            ])
            ->assertRedirect(route('refunds.show', $refund));

        Http::assertNothingSent();
        $refund->refresh();
        $this->assertSame(RefundStatus::Revoked, $refund->status);
    }

    public function test_existing_manual_commercial_restore_still_works(): void
    {
        [$admin, $refund, , $incident] = $this->completedWalletRefundFixture(
            orderNumber: 'RD3454444',
            reference: 'REF-2026-000020',
        );

        $this->actingAs($admin)
            ->postJson(route('dashboard.service-cases.customer-360.commercial-service-restore', [
                'incident' => $incident,
                'refund' => $refund,
            ]), [
                'finance_verified' => '1',
                'wallet_reversed_externally' => '1',
                'wallet_reversal_reference' => 'MANUAL-REV',
            ])
            ->assertOk();

        $this->assertSame(
            CommercialState::ServiceRestored,
            app(CommercialStateResolver::class)->forIncident($incident->fresh())->state,
        );
    }

    /**
     * @return array{0: User, 1: RefundRequest, 2: Order, 3: Incident}
     */
    private function completedWalletRefundFixture(
        string $orderNumber = 'RD3437801',
        string $amount = '499.00',
        string $reference = 'REF-2026-000296',
        string $walletTransactionId = '2559',
    ): array {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderNumber,
            'serial_number' => 'SN-'.$orderNumber,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $amount,
            'customer_email' => 'customer@example.com',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => 'SC-'.substr($reference, -6),
            'category' => 'Refund',
            'source' => 'internal',
            'title' => 'Refund revoke test',
            'description' => 'Refund revoke test incident.',
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => $reference,
            'amount' => $amount,
            'refund_amount' => $amount,
            'reason' => 'Wallet refund revoke test.',
            'status' => RefundStatus::Closed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDay(),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
            'execution_reference_no' => $walletTransactionId,
            'execution_transaction_id' => $walletTransactionId,
            'communication_channels' => [],
        ]);

        return [$admin, $refund, $order, $incident];
    }
}
