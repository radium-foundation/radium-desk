<?php

namespace Tests\Feature\ReturnExchange;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\ReturnExchangeAllocationWalletCreditService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\ReturnExchange\Concerns\PreparesSplitTenderOnlineReturnCases;

class ReturnExchangeAllocationDeskAuthoritativeWalletCreditTest extends ReturnExchangeCaseHttpTestCase
{
    use PreparesSplitTenderOnlineReturnCases;

    public function test_authoritative_policy_blocks_legacy_spoke_wallet_credit(): void
    {
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
            'central_wallet.wallet_refund.desk_first_enabled' => false,
            'radiumbox.wallet_refund_credit_enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-box-wallet-token',
        ]);

        Http::fake();

        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->submitAndApproveAllocation($case, $branchUser);
        $line = $case->fresh()->onlineRefundAllocation->lines->firstWhere(
            'destination',
            OnlineReturnRefundAllocationDestination::Wallet,
        );

        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'wallet-auth-block-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $execution = OnlineReturnRefundAllocationLineExecution::query()->firstOrFail();
        $this->assertSame(OnlineReturnRefundAllocationLineExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED', (string) $execution->failure_reason);
        Http::assertNothingSent();
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
    }

    public function test_desk_first_posts_ledger_before_radiumbox_audit(): void
    {
        config([
            'return_exchange.online_refund_allocation.execution_enabled' => true,
            'central_wallet.wallet_refund.desk_authoritative_enabled' => true,
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'radiumbox.com',
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
                    'wallet_reference' => 'CW-AUDIT',
                    'credit' => '499.00',
                    'balance' => '0.00',
                    'destination' => 'central_wallet',
                ],
            ], 201),
        ]);

        [$case, $branchUser, $order] = $this->inspectedSplitTenderCase();
        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);
        $order->update(['customer_id' => $customerId]);

        $this->submitAndApproveAllocation($case, $branchUser);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $line = $allocation->lines->firstWhere('destination', OnlineReturnRefundAllocationDestination::Wallet);
        $deskRef = app(ReturnExchangeAllocationWalletCreditService::class)->deskReference($allocation, $line);

        $this->actingAs($this->centralAdmin())
            ->post(route('online-return-refund-allocation-lines.execute', $line), [
                'idempotency_key' => 'wallet-desk-first-1',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $execution = OnlineReturnRefundAllocationLineExecution::query()->firstOrFail();
        $this->assertSame(OnlineReturnRefundAllocationLineExecutionStatus::Succeeded, $execution->status);
        $this->assertSame('desk_first_central_wallet', $execution->executor_provider);
        $this->assertStringStartsWith('CW:', (string) $execution->execution_reference);

        $entry = CentralWalletLedgerEntry::query()->where('business_reference', $deskRef)->firstOrFail();
        $this->assertSame(LedgerEntryType::Credit, $entry->entry_type);
        $this->assertSame((string) $execution->transaction_id, (string) $entry->id);

        Http::assertSent(function ($request) use ($deskRef, $entry) {
            return $request->url() === 'https://radiumbox.test/api/integrations/v1/wallet-refunds'
                && ($request['central_wallet_ledger_entry_id'] ?? null) === $entry->id
                && $request['desk_refund_reference'] === $deskRef;
        });
    }

    /**
     * @param  ReturnExchangeCase  $case
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
