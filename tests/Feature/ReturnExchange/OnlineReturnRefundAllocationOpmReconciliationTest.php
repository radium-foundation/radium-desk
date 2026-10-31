<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\AllocationOpmReservationState;
use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeAllocationOpmReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\ReturnExchange\Concerns\PreparesSplitTenderOnlineReturnCases;

class OnlineReturnRefundAllocationOpmReconciliationTest extends ReturnExchangeCaseHttpTestCase
{
    use PreparesSplitTenderOnlineReturnCases;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->applyConversionConfig(true);
    }

    public function test_reserve_lost_response_then_idempotent_recovery(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::sequence()
                ->push(['message' => 'Site adapter rejected the request.'], 502)
                ->push([
                    'data' => [
                        'reservation_id' => 'recovered-res-1',
                        'status' => 'reserved',
                        'idempotent_replay' => true,
                    ],
                ], 200),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/recovered-res-1/confirm' => Http::response([
                'data' => ['reservation_id' => 'recovered-res-1', 'status' => 'reserved'],
            ], 200),
        ]);

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '150.00',
            'idempotency_key' => 'recon-res-1',
        ]);
        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $this->actingAs($this->financeApprover())
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion));

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);
        $this->assertSame('reservation_ambiguous', $conversion->failure_code);
        $this->assertSame('reserve:'.$conversion->public_id, $conversion->site_reservation_idempotency_key);

        $response = $this->actingAs($this->financeReconciler())
            ->post(route('online-return-refund-allocation-opm-conversions.reconcile-reservation', $conversion), [
                'notes' => 'Replay after timeout',
            ]);
        $response->assertRedirect()->assertSessionDoesntHaveErrors();

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Reserved, $conversion->status);
        $this->assertSame('recovered-res-1', $conversion->site_reservation_id);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'wallet-opm-conversion-reservations/recovered-res-1/confirm'));
    }

    public function test_release_blocked_while_reservation_ambiguous(): void
    {
        $conversion = $this->ambiguousReservationFixture();
        $this->actingAs($this->financeReleaseOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.release', $conversion), ['reason' => 'Try'])
            ->assertForbidden();
    }

    public function test_finalize_recovery_replays_without_second_debit(): void
    {
        $conversion = $this->payoutReadyForReconciliation('rec-fin-1', '100.00');
        $conversion->update([
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::InFlight,
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'failure_code' => 'wallet_finalization_in_flight',
            'cashfree_verified_at' => now(),
        ]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/*' => Http::response([
                'data' => ['wallet_reversal_reference' => 'REV-REC-1', 'idempotent_replay' => true],
            ], 200),
        ]);

        $this->actingAs($this->financeReconciler())
            ->post(route('online-return-refund-allocation-opm-conversions.reconcile-finalization', $conversion), [
                'notes' => 'Recover in-flight finalize',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Completed, $conversion->status);
        $this->assertSame('REV-REC-1', $conversion->wallet_reversal_reference);
        Http::assertSentCount(1);
    }

    public function test_unknown_finalize_outcome_stays_held(): void
    {
        $conversion = $this->payoutReadyForReconciliation('rec-fin-2', '80.00');
        $conversion->update([
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Ambiguous,
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'cashfree_verified_at' => now(),
        ]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/*' => Http::response([
                'message' => 'Site adapter rejected the request.',
            ], 500),
        ]);

        $this->actingAs($this->financeReconciler())
            ->post(route('online-return-refund-allocation-opm-conversions.reconcile-finalization', $conversion), [
                'notes' => 'Still unknown',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);
    }

    public function test_same_key_submit_with_different_amount_rejected(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '100.00',
            'idempotency_key' => 'recon-idem-amount',
        ]);
        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '200.00',
                'idempotency_key' => 'recon-idem-amount',
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_tampered_wallet_evidence_blocks_payout(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        Http::fake($this->reserveHttpFake('res-tamper'));
        $this->actingAs($branchUser)->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
            'amount' => '100.00',
            'idempotency_key' => 'recon-tamper',
        ]);
        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $this->actingAs($this->financeApprover())
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion));
        $line->successfulExecution->update(['execution_reference' => 'TAMPERED']);

        $this->actingAs($this->financePayoutOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.record-payout', $conversion), [
                'cashfree_order_id' => 'cf-1',
                'cashfree_refund_id' => 'cf-ref-tamper',
                'cashfree_refund_amount' => '100.00',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');
    }

    public function test_reconciler_cannot_be_requester(): void
    {
        $conversion = $this->ambiguousReservationFixture();
        $requester = User::query()->findOrFail($conversion->requester_id);
        $requester->givePermissionTo(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RECONCILE);

        $this->expectExceptionMessage('different user than the requester');
        app(ReturnExchangeAllocationOpmReconciliationService::class)->recoverReservation(
            $requester,
            $conversion,
            'notes',
        );
    }

    public function test_release_failure_surfaces_operator_error(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-rel-fail/release' => Http::response([
                'message' => 'Reservation not found',
            ], 404),
        ]);

        $conversion = $this->reservedFixture('res-rel-fail');
        $this->actingAs($this->financeReleaseOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.release', $conversion), ['reason' => 'Cleanup'])
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');

        $conversion->refresh();
        $this->assertSame('release_failed', $conversion->site_reservation_release_outcome);
        $this->assertSame(WalletRefundOpmConversionStatus::Reserved, $conversion->status);
    }

    private function ambiguousReservationFixture(): OnlineReturnRefundAllocationOpmConversion
    {
        $conversion = $this->reservedFixture('res-amb-fixture');
        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'failure_code' => 'reservation_ambiguous',
            'site_reservation_state' => AllocationOpmReservationState::Ambiguous,
            'site_reservation_idempotency_key' => 'reserve:'.$conversion->public_id,
        ]);

        return $conversion->fresh() ?? $conversion;
    }

    private function reservedFixture(string $reservationId): OnlineReturnRefundAllocationOpmConversion
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $execution = $line->successfulExecution;

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
            'site_reservation_state' => AllocationOpmReservationState::Confirmed,
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::NotSent,
        ]);
    }

    private function payoutReadyForReconciliation(string $reservationId, string $amount): OnlineReturnRefundAllocationOpmConversion
    {
        $conversion = $this->reservedFixture($reservationId);
        $conversion->update([
            'amount' => $amount,
            'status' => WalletRefundOpmConversionStatus::PayoutRecorded,
            'cashfree_order_id' => 'cf-order',
            'cashfree_refund_id' => 'cf-ref-'.$reservationId,
            'cashfree_refund_amount' => $amount,
            'payout_operator_id' => $this->financePayoutOperator()->id,
            'wallet_finalization_idempotency_key' => 'return-allocation-opm:'.$conversion->public_id,
        ]);

        return $conversion->fresh() ?? $conversion;
    }

    /**
     * @return array<string, mixed>
     */
    private function reserveHttpFake(string $reservationId): array
    {
        return [
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => $reservationId, 'status' => 'reserved'],
            ], 201),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/'.$reservationId.'/confirm' => Http::response([
                'data' => ['reservation_id' => $reservationId, 'status' => 'reserved'],
            ], 200),
        ];
    }

    private function applyConversionConfig(bool $enabled): void
    {
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'return_exchange.online_refund_allocation.opm_conversion_enabled' => $enabled,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => false,
            'central_wallet.wallet_refund.desk_first_enabled' => false,
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
                'idempotency_key' => 'wallet-recon-'.random_int(1, 99999),
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
        return $this->centralFinanceUser(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_APPROVE);
    }

    private function financePayoutOperator(): User
    {
        return $this->centralFinanceUser(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_PAYOUT);
    }

    private function financeReleaseOperator(): User
    {
        return $this->centralFinanceUser(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RELEASE);
    }

    private function financeReconciler(): User
    {
        return $this->centralFinanceUser(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RECONCILE);
    }

    private function centralFinanceUser(string $permission): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CENTRAL,
            $permission,
        ]);

        return $user;
    }
}
