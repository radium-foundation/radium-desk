<?php

namespace Tests\Unit;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Support\Dashboard\DashboardIncidentSortComparator;
use App\Support\Dashboard\NullDashboardAttentionScoreCalculator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardIncidentSortComparatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sorts_by_dashboard_rank_then_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $olderHighPriority = $this->createPendingIncident($user, 'RD-OLDER-HP', highPriority: true, createdAt: now()->subHours(10));
        $newerNormal = $this->createPendingIncident($user, 'RD-NEWER', highPriority: false, createdAt: now()->subHours(2));
        $olderNormal = $this->createPendingIncident($user, 'RD-OLDER', highPriority: false, createdAt: now()->subHours(8));

        $sorted = (new DashboardIncidentSortComparator(new NullDashboardAttentionScoreCalculator()))
            ->sort(collect([$newerNormal, $olderNormal, $olderHighPriority]))
            ->values();

        $this->assertSame(
            [$olderHighPriority->id, $olderNormal->id, $newerNormal->id],
            $sorted->pluck('id')->all(),
        );
    }

    private function createPendingIncident(
        User $user,
        string $orderId,
        bool $highPriority = false,
        ?Carbon $createdAt = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-'.substr(md5($orderId), 0, 8),
            'category' => 'General',
            'source' => 'call',
            'title' => "Case {$orderId}",
            'description' => "Case {$orderId}.",
            'status' => 'open',
            'high_priority' => $highPriority,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        if ($createdAt !== null) {
            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $incident->fresh();
    }
}
