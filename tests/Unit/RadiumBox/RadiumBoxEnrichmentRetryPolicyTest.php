<?php

namespace Tests\Unit\RadiumBox;

use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxEnrichmentRetryPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RadiumBoxEnrichmentRetryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private RadiumBoxEnrichmentRetryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->policy = app(RadiumBoxEnrichmentRetryPolicy::class);
    }

    public function test_new_order_requires_one_hour_between_attempts(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $order = $this->createOrder('2026-06-30 10:00:00');

        $this->assertSame(1, $this->policy->requiredIntervalHours($order));
        $this->assertFalse($this->policy->hasRetryIntervalElapsed($order, '2026-06-30 11:30:00'));
        $this->assertTrue($this->policy->hasRetryIntervalElapsed($order, '2026-06-30 10:30:00'));
    }

    public function test_order_between_six_and_twenty_four_hours_requires_four_hour_interval(): void
    {
        Carbon::setTestNow('2026-06-30 18:00:00');

        $order = $this->createOrder('2026-06-30 08:00:00');

        $this->assertSame(4, $this->policy->requiredIntervalHours($order));
        $this->assertFalse($this->policy->hasRetryIntervalElapsed($order, '2026-06-30 15:00:00'));
        $this->assertTrue($this->policy->hasRetryIntervalElapsed($order, '2026-06-30 13:00:00'));
    }

    public function test_order_on_day_two_requires_twelve_hour_interval(): void
    {
        Carbon::setTestNow('2026-07-01 12:00:00');

        $order = $this->createOrder('2026-06-29 12:00:00');

        $this->assertSame(12, $this->policy->requiredIntervalHours($order));
        $this->assertFalse($this->policy->hasRetryIntervalElapsed($order, '2026-07-01 03:00:00'));
        $this->assertTrue($this->policy->hasRetryIntervalElapsed($order, '2026-06-30 23:00:00'));
    }

    public function test_order_on_day_five_requires_twenty_four_hour_interval(): void
    {
        Carbon::setTestNow('2026-07-05 12:00:00');

        $order = $this->createOrder('2026-06-30 12:00:00');

        $this->assertSame(24, $this->policy->requiredIntervalHours($order));
        $this->assertFalse($this->policy->hasRetryIntervalElapsed($order, '2026-07-04 15:00:00'));
        $this->assertTrue($this->policy->hasRetryIntervalElapsed($order, '2026-07-04 11:00:00'));
    }

    public function test_order_older_than_seven_days_is_outside_automatic_window(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');

        $order = $this->createOrder('2026-06-30 12:00:00');

        $this->assertFalse($this->policy->isWithinAutomaticWindow($order));
        $this->assertGreaterThan(7, $this->policy->orderAgeDays($order));
    }

    private function createOrder(string $createdAt): Order
    {
        $agent = User::factory()->create();
        $timestamp = Carbon::parse($createdAt);

        $order = Order::query()->create([
            'order_id' => 'RD-POLICY-'.str_replace([' ', ':', '-'], '', $createdAt),
            'cashfree_payment_id' => '5898000001',
            'payment_date' => $timestamp,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $order->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();

        return $order->fresh();
    }
}
