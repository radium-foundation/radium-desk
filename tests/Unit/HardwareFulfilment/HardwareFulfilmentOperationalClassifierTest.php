<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentOperationalClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaiting_review_candidate_and_hold_orders(): void
    {
        $classifier = app(HardwareFulfilmentOperationalClassifier::class);
        $review = $this->order('RDE971001', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $hold = $this->order('RDE255714', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $reviewRow = $classifier->fromAwaiting($review);
        $holdRow = $classifier->fromAwaiting($hold);

        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingFulfilment, $reviewRow->stage);
        $this->assertSame('Review order', $reviewRow->nextAction);
        $this->assertFalse($reviewRow->hasFulfilment);
        $this->assertSame(HardwareFulfilmentOperationalStage::BlockedReview, $holdRow->stage);
        $this->assertSame('HOLD', $holdRow->nextAction);
    }

    public function test_existing_fulfilment_without_serial_is_awaiting_serial(): void
    {
        $fulfilment = $this->fulfilment('RDE971002', HardwareFulfilmentState::ReadyForFulfilment);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingSerial, $row->stage);
        $this->assertSame('Allocate Serial', $row->nextAction);
        $this->assertTrue($row->hasFulfilment);
        $this->assertNotNull($row->fulfilmentUrl());
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

    private function fulfilment(string $sourceId, HardwareFulfilmentState $state): HardwareFulfilment
    {
        $order = $this->order($sourceId, [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
        ]);
    }
}
