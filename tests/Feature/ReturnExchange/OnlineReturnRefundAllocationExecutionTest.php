<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\OnlineReturnRefundAllocationDestination;
use Database\Seeders\FinanceMasterDataSeeder;
use Tests\Feature\ReturnExchange\Concerns\PreparesSplitTenderOnlineReturnCases;
use Spatie\Permission\PermissionRegistrar;
use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\RefundRequest;
use App\Services\ReturnExchange\OnlineReturnRefundAllocationExecutionService;
use App\Services\ReturnExchange\ReturnExchangeAllocationWalletCreditService;
use Illuminate\Support\Facades\Http;

class OnlineReturnRefundAllocationExecutionTest extends ReturnExchangeCaseHttpTestCase
{
    use PreparesSplitTenderOnlineReturnCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['return_exchange.online_refund_allocation.execution_enabled' => false]);
    }

    public function test_execution_rejected_when_flag_disabled_and_no_http(): void
    {
        Http::fake();
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Wallet,
        );
        $this->assertNotNull($line);

        $central = $this->centralAdmin();
        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'exec-disabled-1',
            ])
            ->assertRedirect(route('return-cases.show', $case))
            ->assertSessionHasErrors('execution');

        Http::assertNothingSent();
        $this->assertSame(0, OnlineReturnRefundAllocationLineExecution::query()->count());
    }

    public function test_branch_user_cannot_execute_allocation_line(): void
    {
        config(['return_exchange.online_refund_allocation.execution_enabled' => true]);
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->first();

        $this->actingAs($this->agentUser())
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'exec-auth-1',
            ])
            ->assertForbidden();
    }

    public function test_wallet_line_executes_via_radiumbox_client_with_idempotency(): void
    {
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);
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

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $line = $allocation->lines->firstWhere('destination', OnlineReturnRefundAllocationDestination::Wallet);
        $deskRef = app(ReturnExchangeAllocationWalletCreditService::class)->deskReference($allocation, $line);

        $central = $this->centralAdmin();
        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'wallet-exec-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $execution = OnlineReturnRefundAllocationLineExecution::query()->first();
        $this->assertSame(
            OnlineReturnRefundAllocationLineExecutionStatus::Succeeded,
            $execution->status,
            (string) ($execution->failure_reason ?? 'no failure reason'),
        );
        $this->assertSame('radiumbox_wallet', $execution->executor_provider);
        $this->assertSame('RD9001', $execution->execution_reference);
        $this->assertSame('9001', $execution->transaction_id);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($deskRef): bool {
            return $request['desk_refund_reference'] === $deskRef;
        });

        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'wallet-exec-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        Http::assertSentCount(1);
        $this->assertSame(1, OnlineReturnRefundAllocationLineExecution::query()->count());
    }

    public function test_cashfree_line_requires_manual_reference(): void
    {
        config(['return_exchange.online_refund_allocation.execution_enabled' => true]);
        Http::fake();

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Cashfree,
        );

        $central = $this->centralAdmin();
        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'cf-pending-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $pending = OnlineReturnRefundAllocationLineExecution::query()->firstOrFail();
        $this->assertSame(OnlineReturnRefundAllocationLineExecutionStatus::ManualPending, $pending->status);
        Http::assertNothingSent();

        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'cf-done-1',
                'execution_reference' => 'CF-PAYOUT-99',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $succeeded = $line->fresh()->successfulExecution;
        $this->assertNotNull($succeeded);
        $this->assertSame('cashfree_manual', $succeeded->executor_provider);
        $this->assertSame('CF-PAYOUT-99', $succeeded->execution_reference);
        Http::assertNothingSent();
    }

    public function test_opm_destination_records_held_without_side_effects(): void
    {
        config(['return_exchange.online_refund_allocation.execution_enabled' => true]);
        Http::fake();
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Opm->value, 'amount' => '1770.00'],
                ],
            ]);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocations.review', $allocation), [
                'decision' => 'approve',
            ]);
        $line = $allocation->fresh()->lines->first();

        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'opm-held-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $held = OnlineReturnRefundAllocationLineExecution::query()->firstOrFail();
        $this->assertSame(OnlineReturnRefundAllocationLineExecutionStatus::Held, $held->status);
        $this->assertStringContainsString('OPM', (string) $held->held_reason);
        Http::assertNothingSent();
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_cannot_re_execute_after_successful_line(): void
    {
        config(['return_exchange.online_refund_allocation.execution_enabled' => true]);
        Http::fake();

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Cashfree,
        );

        $central = $this->centralAdmin();
        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'cf-once',
                'execution_reference' => 'CF-ONCE',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $this->actingAs($central)
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'cf-twice',
                'execution_reference' => 'CF-TWICE',
            ])
            ->assertRedirect(route('return-cases.show', $case))
            ->assertSessionHasErrors('execution');
    }

    public function test_service_default_off_without_config_override(): void
    {
        config(['return_exchange.online_refund_allocation.execution_enabled' => false]);
        $this->assertFalse(app(OnlineReturnRefundAllocationExecutionService::class)->isExecutionEnabled());
    }

    /**
     * @param  \App\Models\ReturnExchangeCase  $case
     */
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
}
