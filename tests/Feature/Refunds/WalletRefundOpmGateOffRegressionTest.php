<?php

namespace Tests\Feature\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCalculationService;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionOrderLimitService;
use App\Support\Refunds\WalletRefundOpmConversionUiPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletRefundOpmGateOffRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'wallet_refund_opm_conversion.sites.radiumbox.com.enabled' => false,
            'wallet_refund_opm_conversion.sites.rdservice.in.enabled' => false,
            'wallet_refund_opm_conversion.sites.rdservice.net.enabled' => false,
        ]);
    }

    public function test_maximum_refundable_unchanged_when_opm_table_absent(): void
    {
        Schema::dropIfExists('wallet_refund_opm_conversions');

        $order = Order::query()->create([
            'order_id' => 'RB9000999',
            'serial_number' => 'SN',
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => 1000,
            'cashfree_payment_id' => 'cf-1',
            'created_by' => User::factory()->create()->id,
        ]);

        $max = app(RefundCalculationService::class)->maximumRefundable($order);

        $this->assertSame(1000.0, $max);
        $this->assertSame(0.0, app(WalletRefundOpmConversionOrderLimitService::class)->committedAmountForOrder($order));
    }

    public function test_refund_show_ui_reports_site_disabled_without_audit_log(): void
    {
        $refund = $this->completedWalletRefundWithoutAudit('RB9000888');
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $ui = app(WalletRefundOpmConversionUiPresenter::class)->forRefund($refund, $agent);

        $this->assertFalse($ui['can_request']);
        $this->assertFalse($ui['site_enabled']);
        $this->assertStringContainsString('not enabled', strtolower((string) $ui['request_block_reason']));
    }

    private function completedWalletRefundWithoutAudit(string $orderId): RefundRequest
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Device',
            'device_model' => 'X',
            'status' => 'active',
            'payment_amount' => 500,
            'cashfree_payment_id' => 'cf-pay-'.$orderId,
            'created_by' => $user->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.substr($orderId, -4),
            'title' => 'Incident',
            'description' => 'Test',
            'category' => 'Hardware',
            'source' => 'internal',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        return RefundRequest::query()->create([
            'reference_no' => 'REF-'.substr($orderId, -6),
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'refund_amount' => 500,
            'amount' => 500,
            'requested_by' => $user->id,
            'reason' => 'Completed wallet refund.',
        ]);
    }
}
