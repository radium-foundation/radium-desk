<?php

namespace Tests\Unit\Refunds;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCalculationService;
use App\Services\Refunds\RefundWalletAutoApprovalEvaluator;
use App\Services\Refunds\WalletRefundDestinationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundWalletAutoApprovalEvaluatorTest extends TestCase
{
    use RefreshDatabase;
    public function test_missing_flag_defaults_to_disabled(): void
    {
        config(['refunds.wallet_auto_approval.enabled' => null]);

        $evaluator = new RefundWalletAutoApprovalEvaluator(
            app(WalletRefundDestinationResolver::class),
            app(CashfreeCentralCustomerBinder::class),
        );

        $this->assertFalse($evaluator->isEnabled());
    }

    public function test_parse_spoke_list_and_effective_intersection(): void
    {
        config([
            'refunds.wallet_auto_approval.spokes' => ' rdservice.in , radiumbox.com , rdservice.net ',
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in,radiumbox.com',
        ]);

        $evaluator = app(RefundWalletAutoApprovalEvaluator::class);

        $this->assertSame(
            ['rdservice.in', 'radiumbox.com'],
            $evaluator->effectivePilotSpokeOwners(),
        );
    }

    public function test_max_amount_defaults_and_normalizes(): void
    {
        config(['refunds.wallet_auto_approval.max_amount' => 2000]);

        $evaluator = app(RefundWalletAutoApprovalEvaluator::class);

        $this->assertSame('2000.00', $evaluator->maxAmount());
    }

    public function test_float_refund_amounts_evaluate_full_refund_within_cap(): void
    {
        config([
            'refunds.wallet_auto_approval.enabled' => true,
            'refunds.wallet_auto_approval.spokes' => 'rdservice.in',
            'refunds.wallet_auto_approval.max_amount' => '2000',
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => 'rdservice.in',
        ]);

        $agent = User::factory()->create();
        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD3438010',
            'customer_id' => $customerId,
            'serial_number' => 'SN-8010',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'payment_amount' => '1500.00',
            'customer_email' => 'float-amount@example.com',
            'created_by' => $agent->id,
        ]);

        $calculation = app(RefundCalculationService::class)->calculate($order, [
            'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-FLOAT-1',
            'amount' => $calculation->refundAmount,
            'refund_amount' => $calculation->refundAmount,
            'reason' => 'Float amount normalization regression.',
            'status' => RefundStatus::Pending,
            'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet,
            'requested_by' => $agent->id,
        ]);

        $evaluation = app(RefundWalletAutoApprovalEvaluator::class)->evaluate($refund, $order, $calculation);

        $this->assertTrue($evaluation['eligible'], implode('; ', $evaluation['reasons']));
    }
}
