<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeAllocationOpmConversionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\ReturnExchange\Concerns\PreparesSplitTenderOnlineReturnCases;

class OnlineReturnRefundAllocationOpmSafetyTest extends ReturnExchangeCaseHttpTestCase
{
    use PreparesSplitTenderOnlineReturnCases;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->applyConversionConfig(true);
    }

    public function test_reservation_failed_with_extant_reservation_can_release(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => 'res-fail-1', 'status' => 'reserved'],
            ], 201),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-fail-1/confirm' => Http::response([
                'message' => 'Reservation not found',
            ], 404),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-fail-1/release' => Http::response([
                'data' => ['reservation_id' => 'res-fail-1', 'status' => 'released', 'idempotent_replay' => false],
            ], 200),
        ]);

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '200.00',
            'idempotency_key' => 'safety-res-fail-1',
        ]);
        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $this->actingAs($this->financeApprover())
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion));

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReservationFailed, $conversion->status);
        $this->assertTrue($conversion->site_reservation_may_exist);
        $this->assertSame('res-fail-1', $conversion->site_reservation_id);

        $releaseUser = $this->financeReleaseOperator();
        $this->actingAs($releaseUser)
            ->post(route('online-return-refund-allocation-opm-conversions.release', $conversion), [
                'reason' => 'Cleanup after failed confirm',
            ])
            ->assertRedirect();

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Cancelled, $conversion->status);
        $this->assertNotNull($conversion->site_reservation_released_at);
        $this->assertSame('released', $conversion->site_reservation_release_outcome);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/release');
        });
    }

    public function test_release_spoke_idempotent_replay_outcome_is_persisted(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-repeat/release' => Http::response([
                'data' => ['status' => 'released', 'idempotent_replay' => true],
            ], 200),
        ]);

        $conversion = $this->reservedConversionFixture('res-repeat');
        $this->actingAs($this->financeReleaseOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.release', $conversion), ['reason' => 'Cleanup'])
            ->assertRedirect();

        $conversion->refresh();
        $this->assertSame('release_replay', $conversion->site_reservation_release_outcome);
        $this->assertNotNull($conversion->site_reservation_released_at);
    }

    public function test_finalize_idempotent_replay_does_not_duplicate_debit(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-fin-1/finalize-reversal' => Http::response([
                'data' => ['wallet_reversal_reference' => 'REV-1', 'idempotent_replay' => true],
            ], 200),
            'https://api.cashfree.com/pg/orders/*' => Http::response([
                'refund_status' => 'SUCCESS',
                'refund_amount' => 150,
            ], 200),
        ]);

        $conversion = $this->payoutReadyConversion('res-fin-1', '150.00');
        $conversion->update([
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Ambiguous,
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'cashfree_verified_at' => now(),
        ]);

        $this->actingAs($this->financePayoutOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.verify-finalize', $conversion))
            ->assertRedirect();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'finalize-reversal'));
        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);

        $conversion->update([
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Confirmed,
            'wallet_reversal_reference' => 'REV-1',
        ]);

        $this->actingAs($this->financePayoutOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.verify-finalize', $conversion))
            ->assertRedirect();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'finalize-reversal'));
        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Completed, $conversion->status);
    }

    public function test_ambiguous_finalization_blocks_http_retry_from_reconciliation_hold(): void
    {
        $conversion = $this->payoutReadyConversion('res-amb-1', '100.00');
        Http::fake([
            'https://api.cashfree.com/pg/orders/*' => Http::response([
                'refund_status' => 'SUCCESS',
                'refund_amount' => 100,
            ], 200),
        ]);
        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Ambiguous,
            'cashfree_verified_at' => now(),
        ]);

        $this->actingAs($this->financePayoutOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.verify-finalize', $conversion))
            ->assertRedirect();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'finalize-reversal'));
        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);
        $this->assertSame(AllocationOpmWalletFinalizationState::Ambiguous, $conversion->wallet_finalization_state);
    }

    public function test_same_key_concurrent_submit_returns_single_row(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $payload = ['amount' => '100.00', 'idempotency_key' => 'safety-idem-race'];

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), $payload);
        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), $payload);

        $this->assertSame(1, OnlineReturnRefundAllocationOpmConversion::query()->count());
    }

    public function test_mismatched_wallet_execution_reference_rejected_on_approve(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '100.00',
            'idempotency_key' => 'safety-mismatch-1',
        ]);
        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $line->successfulExecution->update(['execution_reference' => 'TAMPERED']);

        $this->actingAs($this->financeApprover())
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion))
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');
    }

    public function test_payout_operator_cannot_be_approver(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '100.00',
            'idempotency_key' => 'safety-sod-1',
        ]);
        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $approver = $this->financeApprover();
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => 'res-sod-1', 'status' => 'reserved'],
            ], 201),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-sod-1/confirm' => Http::response([
                'data' => ['reservation_id' => 'res-sod-1', 'status' => 'reserved'],
            ], 200),
        ]);
        $this->actingAs($approver)->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion));

        $conversion->refresh();
        $this->actingAs($approver)
            ->post(route('online-return-refund-allocation-opm-conversions.record-payout', $conversion), [
                'cashfree_order_id' => 'cf-1',
                'cashfree_refund_id' => 'cf-ref-sod',
                'cashfree_refund_amount' => '100.00',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');
    }

    public function test_service_records_bound_wallet_evidence_on_submit(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $execution = $line->successfulExecution;
        $conversion = app(ReturnExchangeAllocationOpmConversionService::class)->submit(
            $branchUser,
            $line,
            $execution,
            ['amount' => '50.00', 'idempotency_key' => 'evidence-1'],
        );

        $this->assertSame($execution->execution_reference, $conversion->wallet_execution_reference);
        $this->assertSame($execution->transaction_id, $conversion->wallet_transaction_id);
        $this->assertNotNull($conversion->bound_order_id);
        $this->assertSame('499.00', (string) $conversion->bound_wallet_credit_amount);
        $this->assertStringContainsString('-L'.$line->line_no, $conversion->desk_wallet_reference);
    }

    private function reservedConversionFixture(string $reservationId): OnlineReturnRefundAllocationOpmConversion
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $execution = $line->successfulExecution;
        $allocation = $case->fresh()->onlineRefundAllocation;

        return OnlineReturnRefundAllocationOpmConversion::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'return_exchange_case_id' => $case->id,
            'online_return_refund_allocation_id' => $allocation->id,
            'online_return_refund_allocation_line_id' => $line->id,
            'online_return_refund_allocation_line_execution_id' => $execution->id,
            'desk_wallet_reference' => $allocation->reference_no.'-L'.$line->line_no,
            'bound_order_id' => $case->order?->order_id,
            'bound_wallet_credit_amount' => $line->amount,
            'wallet_executor_provider' => $execution->executor_provider,
            'wallet_execution_reference' => $execution->execution_reference,
            'wallet_transaction_id' => $execution->transaction_id,
            'status' => WalletRefundOpmConversionStatus::Reserved,
            'site_code' => 'radiumbox.com',
            'wallet_credit_source' => \App\Enums\WalletRefundCreditSource::LegacyLocal,
            'amount' => '100.00',
            'currency' => 'INR',
            'requester_id' => $branchUser->id,
            'approver_id' => $this->financeApprover()->id,
            'site_reservation_id' => $reservationId,
            'site_reservation_may_exist' => true,
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::NotSent,
        ]);
    }

    private function payoutReadyConversion(string $reservationId, string $amount): OnlineReturnRefundAllocationOpmConversion
    {
        $conversion = $this->reservedConversionFixture($reservationId);
        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::PayoutRecorded,
            'amount' => $amount,
            'cashfree_order_id' => 'cf-order',
            'cashfree_refund_id' => 'cf-ref-'.$reservationId,
            'cashfree_refund_amount' => $amount,
            'payout_operator_id' => $this->financePayoutOperator()->id,
            'wallet_finalization_idempotency_key' => 'return-allocation-opm:'.$conversion->public_id,
        ]);

        return $conversion->fresh() ?? $conversion;
    }

    private function applyConversionConfig(bool $enabled): void
    {
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'return_exchange.online_refund_allocation.opm_conversion_enabled' => $enabled,
            'wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => $enabled,
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
            'cashfree.api.app_id' => 'test-app',
            'cashfree.api.secret' => 'test-secret',
            'cashfree.api.base_url' => 'https://api.cashfree.com/pg',
        ]);
    }

    private function executeWalletLine($case, $branchUser)
    {
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Wallet,
        );
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-refunds' => Http::response([
                'status' => 201,
                'data' => [
                    'wallet_transaction_id' => 9001,
                    'wallet_reference' => 'RD9001',
                    'credit' => 499,
                    'balance' => 499,
                ],
            ], 201),
        ]);
        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'wallet-safety-'.random_int(1, 99999),
            ]);

        return $line->fresh();
    }

    private function submitAndApproveAllocation($case, $branchUser): void
    {
        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                    ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1271.00'],
                ],
            ]);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocations.review', $allocation), [
                'decision' => 'approve',
                'review_reason' => 'OK',
            ]);
    }

    private function financeApprover(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_APPROVE);

        return $user;
    }

    private function financePayoutOperator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_PAYOUT);

        return $user;
    }

    private function financeReleaseOperator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RELEASE);

        return $user;
    }
}
