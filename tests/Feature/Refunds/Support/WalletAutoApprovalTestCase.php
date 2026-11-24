<?php

namespace Tests\Feature\Refunds\Support;

use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class WalletAutoApprovalTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    protected function enablePilotConfig(
        ?string $pilotSpokes = 'rdservice.in,radiumbox.com',
        ?string $deskFirstSpokes = 'rdservice.in,radiumbox.com,rdservice.net',
    ): void {
        config([
            'refunds.wallet_auto_approval.enabled' => true,
            'refunds.wallet_auto_approval.spokes' => $pilotSpokes,
            'refunds.wallet_auto_approval.max_amount' => '2000',
            'central_wallet.wallet_refund.desk_first_enabled' => true,
            'central_wallet.wallet_refund.desk_first_spoke_allowlist' => $deskFirstSpokes,
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: string}
     */
    protected function bindOrder(string $businessOrderId, string $paymentAmount = '1500.00'): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_id' => $businessOrderId,
            'customer_id' => $customerId,
            'serial_number' => 'SN-'.$businessOrderId,
            'product_name' => 'Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $paymentAmount,
            'customer_email' => 'auto-refund@example.com',
            'created_by' => $agent->id,
        ]);

        return [$agent, $order, $customerId];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fullRefundPayload(Order $order, ?int $incidentId = null): array
    {
        return [
            'order_id' => $order->id,
            'incident_id' => $incidentId,
            'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
            'reason' => 'Customer eligible for full wallet refund under pilot rules.',
            'remarks' => 'Pilot auto-approval path test.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
        ];
    }
}
