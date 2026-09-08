<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareAwaitingFulfilmentClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_review_candidate(): void
    {
        $order = $this->order('RDE960001', [
            'cashfree_payment_id' => 'cf_paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $this->assertSame(
            HardwareAwaitingFulfilmentReason::ReviewCandidate,
            HardwareAwaitingFulfilmentClassifier::reason($order),
        );
    }

    public function test_classifies_rin_frozen_hold_blocked_unpaid_historical_and_completed(): void
    {
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Rin,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RIN960002')),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Frozen,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Hold,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0], [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Blocked,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(
                HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0],
                ['cashfree_payment_id' => 'paid'],
            )),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Unpaid,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960003')),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::PreCutoff,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960004', [
                'cashfree_payment_id' => 'paid',
                'created_at' => '2026-09-01 10:00:00',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960005', [
                'cashfree_payment_id' => 'paid',
                'serial_number' => '10500001',
                'created_at' => '2026-09-07 10:00:00',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960006', [
                'cashfree_payment_id' => 'paid',
                'transaction_id' => 'TX-1',
                'created_at' => '2026-09-07 10:00:00',
            ])),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $orderId, array $overrides = []): Order
    {
        $creator = User::factory()->create(['is_active' => true]);
        if (filled($overrides['cashfree_payment_id'] ?? null)) {
            $overrides['cashfree_payment_id'] = 'cf_'.$orderId;
        }

        $order = Order::query()->create(array_merge([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order->fresh();
    }
}
