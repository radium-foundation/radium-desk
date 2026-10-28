<?php

namespace Tests\Feature\CustomerIntake;

use App\Enums\IncidentSource;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeService;
use App\Services\OrderLookup\OrderEnrichmentLookupService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerIntakeLegacySerialGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_legacy_intake_skips_serial_when_another_order_owns_it(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD3463012',
            'serial_number' => '8888888',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $lookup = Mockery::mock(OrderEnrichmentLookupService::class);
        $lookup->shouldReceive('fetchInteractive')
            ->once()
            ->with('RB1046')
            ->andReturn(new RadiumBoxOrderEnrichment(serialNumber: '8888888', deviceModel: 'MFS 110'));

        $this->app->instance(OrderEnrichmentLookupService::class, $lookup);

        $incident = app(CustomerIntakeService::class)->createLegacyFromRadiumBox(
            user: $agent,
            orderId: 'RB1046',
            source: IncidentSource::Call,
            notes: null,
            phone: '9000000000',
        );

        $order = $incident->order;
        $this->assertNotNull($order);
        $this->assertSame('RB1046', $order->order_id);
        $this->assertNull($order->serial_number);
    }
}
