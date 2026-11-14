<?php

namespace Tests\Unit\RadiumBox;

use App\Data\RadiumBox\DeskAuthoritativePaymentEvent;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\DeskAuthoritativePaymentEventFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskAuthoritativePaymentEventFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_stable_idempotency_from_desk_verified_payment(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RBP2138',
            'cashfree_payment_id' => '6998469575',
            'payment_amount' => 2499,
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
        ]);

        $event = app(DeskAuthoritativePaymentEventFactory::class)->fromDeskOrder($order);

        $this->assertSame(DeskAuthoritativePaymentEvent::CONTRACT_VERSION, $event->payload()['contract_version']);
        $this->assertSame(
            'desk-payment:radiumbox_com:RBP2138:6998469575',
            $event->idempotencyKey,
        );
        $this->assertSame('2499.00', $event->amount);
        $this->assertSame('INR', $event->currency);
        $this->assertSame('cashfree_webhook', $event->verificationSource);
    }
}