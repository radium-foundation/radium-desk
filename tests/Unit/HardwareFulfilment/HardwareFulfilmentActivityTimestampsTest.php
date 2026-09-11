<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\User;
use App\Support\HardwareFulfilment\HardwareFulfilmentActivityTimestamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareFulfilmentActivityTimestampsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_last_action_uses_updated_at_when_present(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RDE902040',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
        $order->forceFill([
            'created_at' => Carbon::parse('2026-09-08 10:00:00', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-09-10 19:16:00', 'Asia/Kolkata'),
        ])->saveQuietly();

        $this->assertSame(
            '2026-09-10 19:16',
            HardwareFulfilmentActivityTimestamps::lastActionIstForOrder($order),
        );
    }

    public function test_fulfilment_last_action_uses_latest_activity_field(): void
    {
        $fulfilment = new HardwareFulfilment([
            'ingested_at' => Carbon::parse('2026-09-08 10:00:00', 'Asia/Kolkata'),
            'ready_at' => Carbon::parse('2026-09-09 12:00:00', 'Asia/Kolkata'),
            'serials_allocated_at' => Carbon::parse('2026-09-10 19:16:00', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-09-10 08:00:00', 'Asia/Kolkata'),
        ]);

        $this->assertSame(
            '2026-09-10 19:16',
            HardwareFulfilmentActivityTimestamps::lastActionIstForFulfilment($fulfilment),
        );
    }
}
