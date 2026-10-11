<?php

namespace Tests\Unit\Cashfree;

use App\Enums\LegacyCashfreePaymentDiscoveryClassification;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use App\Services\Cashfree\LegacyCashfreePaymentDiscoveryClassifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyCashfreePaymentDiscoveryClassifierTest extends TestCase
{
    use RefreshDatabase;

    private LegacyCashfreePaymentDiscoveryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->classifier = new LegacyCashfreePaymentDiscoveryClassifier;
    }

    public function test_safe_to_link_for_exact_valid_payment(): void
    {
        $order = $this->makeOrder();

        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::SafeToLink, $result['classification']);
    }

    public function test_already_linked_when_desk_matches_cashfree(): void
    {
        $order = $this->makeOrder([
            'cashfree_payment_id' => '5870797060',
            'payment_amount' => 790,
            'payment_method' => 'UPI',
        ]);

        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::AlreadyLinked, $result['classification']);
    }

    public function test_no_cashfree_payment_when_order_not_paid(): void
    {
        $order = $this->makeOrder();
        $cfOrder = $this->cashfreeOrder('RD100', 'ACTIVE', 790);

        $result = $this->classifier->classify($order, $cfOrder, [], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment, $result['classification']);
    }

    public function test_multiple_payments_classification(): void
    {
        $order = $this->makeOrder();
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);

        $result = $this->classifier->classify($order, $cfOrder, [
            $this->successPayment('1', 790),
            $this->successPayment('2', 790),
        ], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::MultiplePayments, $result['classification']);
    }

    public function test_amount_mismatch_between_order_and_payment(): void
    {
        $order = $this->makeOrder();
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 800);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::AmountMismatch, $result['classification']);
    }

    public function test_customer_mismatch(): void
    {
        $order = $this->makeOrder(['customer_email' => 'a@example.com']);
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790, email: 'b@example.com');
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::CustomerMismatch, $result['classification']);
    }

    public function test_conflicting_desk_payment_id(): void
    {
        $order = $this->makeOrder(['cashfree_payment_id' => 'other-id']);
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::ConflictingDeskPayment, $result['classification']);
    }

    public function test_refund_conflict_from_cashfree(): void
    {
        $order = $this->makeOrder();
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], [['refund_id' => '1']]);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::RefundConflict, $result['classification']);
    }

    public function test_refund_conflict_from_desk_refund_request(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'requested_by' => $user->id,
            'reference_no' => 'REF-TEST-001',
            'status' => RefundStatus::Pending,
            'amount' => 100,
            'reason' => 'test',
        ]);

        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::RefundConflict, $result['classification']);
    }

    public function test_ambiguous_when_merchant_order_id_mismatch(): void
    {
        $order = $this->makeOrder(['order_id' => 'RD100']);
        $cfOrder = $this->cashfreeOrder('RD999', 'PAID', 790);

        $result = $this->classifier->classify($order, $cfOrder, [$this->successPayment('1', 790)], []);

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::Ambiguous, $result['classification']);
    }

    public function test_api_error_maps_to_no_cashfree_payment(): void
    {
        $order = $this->makeOrder();

        $result = $this->classifier->classify(
            $order,
            null,
            [],
            [],
            new CashfreeApiException('Cashfree API GET /orders/RD100 failed with HTTP 404.'),
        );

        $this->assertSame(LegacyCashfreePaymentDiscoveryClassification::NoCashfreePayment, $result['classification']);
    }

    public function test_repeated_classification_is_stable(): void
    {
        $order = $this->makeOrder();
        $cfOrder = $this->cashfreeOrder('RD100', 'PAID', 790);
        $payment = $this->successPayment('5870797060', 790);

        $first = $this->classifier->classify($order, $cfOrder, [$payment], []);
        $second = $this->classifier->classify($order, $cfOrder, [$payment], []);

        $this->assertSame($first['classification'], $second['classification']);
        $this->assertSame($first['reason'], $second['reason']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(array $overrides = []): Order
    {
        $user = User::factory()->create();

        return Order::query()->create(array_merge([
            'order_id' => 'RD100',
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'customer_phone' => '7411777133',
            'legacy_source' => 'radiumbox',
            'status' => OrderStatus::Active,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreeOrder(string $orderId, string $status, float $amount, ?string $email = null): array
    {
        return [
            'order_id' => $orderId,
            'order_status' => $status,
            'order_amount' => $amount,
            'order_currency' => 'INR',
            'cf_order_id' => '6264645755',
            'customer_details' => [
                'customer_email' => $email ?? 'test@example.com',
                'customer_phone' => '7411777133',
                'customer_name' => 'Test Customer',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayment(string $cfPaymentId, float $amount): array
    {
        return [
            'cf_payment_id' => $cfPaymentId,
            'payment_status' => 'SUCCESS',
            'payment_amount' => $amount,
            'payment_currency' => 'INR',
            'payment_group' => 'upi',
            'is_captured' => true,
            'payment_time' => '2026-06-25T19:29:05+05:30',
        ];
    }
}
