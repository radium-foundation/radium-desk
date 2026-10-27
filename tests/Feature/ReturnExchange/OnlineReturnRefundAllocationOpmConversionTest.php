<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeAllocationOpmConversionEligibilityService;
use App\Services\ReturnExchange\ReturnExchangeAllocationWalletCreditService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\ReturnExchange\Concerns\PreparesSplitTenderOnlineReturnCases;

class OnlineReturnRefundAllocationOpmConversionTest extends ReturnExchangeCaseHttpTestCase
{
    use PreparesSplitTenderOnlineReturnCases;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->applyConversionConfig(false);
    }

    public function test_conversion_rejected_when_flag_disabled(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        Http::fake();
        config(['return_exchange.online_refund_allocation.opm_conversion_enabled' => false]);

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '100.00',
                'idempotency_key' => 'opm-off-1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('conversion');

        $this->assertSame(0, OnlineReturnRefundAllocationOpmConversion::query()->count());
    }

    public function test_agent_cannot_request_conversion(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);

        $this->actingAs($this->agentUser())
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '100.00',
            ])
            ->assertForbidden();
    }

    public function test_cannot_convert_without_succeeded_wallet_execution(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Wallet,
        );

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '100.00',
            ])
            ->assertForbidden();
    }

    public function test_happy_path_reservation_manual_payout_verify_and_finalize(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $deskRef = app(ReturnExchangeAllocationWalletCreditService::class)->deskReference($allocation, $line);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => 'res-opm-1', 'status' => 'reserved'],
            ], 201),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-opm-1/confirm' => Http::response([
                'data' => ['reservation_id' => 'res-opm-1', 'status' => 'reserved'],
            ], 200),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-opm-1/finalize-reversal' => Http::response([
                'data' => ['wallet_reversal_reference' => 'rev-1', 'idempotent_replay' => false],
            ], 200),
            'https://api.cashfree.com/pg/orders/*' => Http::response([
                'refund_status' => 'SUCCESS',
                'refund_amount' => 200,
            ], 200),
        ]);

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '200.00',
                'idempotency_key' => 'opm-req-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $this->assertSame(WalletRefundOpmConversionStatus::PendingApproval, $conversion->status);
        $this->assertSame($deskRef, $conversion->desk_wallet_reference);

        $approver = $this->financeApprover();
        $payoutOp = $this->financePayoutOperator();

        $this->actingAs($approver)
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion))
            ->assertRedirect();

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Reserved, $conversion->status);
        $this->assertSame('res-opm-1', $conversion->site_reservation_id);

        $this->actingAs($payoutOp)
            ->post(route('online-return-refund-allocation-opm-conversions.record-payout', $conversion), [
                'cashfree_order_id' => 'cf-order-1',
                'cashfree_refund_id' => 'cf-refund-1',
                'cashfree_refund_amount' => '200.00',
            ])
            ->assertRedirect();

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::Completed, $conversion->status);
        $this->assertNotNull($conversion->cashfree_verified_at);
        $this->assertSame('rev-1', $conversion->wallet_reversal_reference);

        Http::assertSent(function ($request) use ($deskRef) {
            if (! str_contains($request->url(), 'finalize-reversal')) {
                return false;
            }

            return ($request['desk_refund_reference'] ?? '') === $deskRef;
        });
    }

    public function test_amount_cannot_exceed_unconverted_wallet_credit(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '999.00',
                'idempotency_key' => 'opm-over-1',
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_submit_idempotency_returns_same_record(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);

        $payload = ['amount' => '100.00', 'idempotency_key' => 'opm-idem-1'];
        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), $payload);
        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), $payload);

        $this->assertSame(1, OnlineReturnRefundAllocationOpmConversion::query()->count());
    }

    public function test_verification_mismatch_holds_reconciliation(): void
    {
        $this->applyConversionConfig(true);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $line = $this->executeWalletLine($case, $branchUser);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations' => Http::response([
                'data' => ['reservation_id' => 'res-opm-2', 'status' => 'reserved'],
            ], 201),
            'https://radiumbox.test/api/integrations/v1/wallet-opm-conversion-reservations/res-opm-2/confirm' => Http::response([
                'data' => ['reservation_id' => 'res-opm-2', 'status' => 'reserved'],
            ], 200),
            'https://api.cashfree.com/pg/orders/*' => Http::response([
                'refund_status' => 'SUCCESS',
                'refund_amount' => 50,
            ], 200),
        ]);

        $this->actingAs($branchUser)
            ->post(route('online-return-refund-allocation-lines.opm-conversions.store', $line), [
                'amount' => '100.00',
                'idempotency_key' => 'opm-mismatch-1',
            ]);

        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->firstOrFail();
        $this->actingAs($this->financeApprover())
            ->post(route('online-return-refund-allocation-opm-conversions.approve', $conversion));

        $conversion->refresh();
        $this->actingAs($this->financePayoutOperator())
            ->post(route('online-return-refund-allocation-opm-conversions.record-payout', $conversion), [
                'cashfree_order_id' => 'cf-order-2',
                'cashfree_refund_id' => 'cf-refund-2',
                'cashfree_refund_amount' => '100.00',
            ]);

        $conversion->refresh();
        $this->assertSame(WalletRefundOpmConversionStatus::ReconciliationHold, $conversion->status);
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
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => false,
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);
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
                'idempotency_key' => 'wallet-for-opm-'.random_int(1, 99999),
            ]);

        $line = $line->fresh();
        $this->assertNotNull($line->successfulExecution);

        return $line;
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
}
