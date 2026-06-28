<?php

namespace Tests\Feature\RadiumBox;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RadiumBoxOrderEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
        ]);
    }

    public function test_order_workspace_enriches_missing_serial_and_device_model_from_radiumbox(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('M250546898')
            ->assertSee('Access FM220U L1');

        $order->refresh();

        $this->assertSame('M250546898', $order->serial_number);
        $this->assertSame('Access FM220U L1', $order->device_model);

        Http::assertSentCount(1);
    }

    public function test_existing_local_serial_and_device_model_are_not_overwritten(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => 'LOCAL-SERIAL-1',
            'product_name' => 'Local Product',
            'device_model' => 'Local Model',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('LOCAL-SERIAL-1')
            ->assertSee('Local Product')
            ->assertDontSee('M250546898')
            ->assertDontSee('Access FM220U L1');

        $order->refresh();

        $this->assertSame('LOCAL-SERIAL-1', $order->serial_number);
        $this->assertSame('Local Model', $order->device_model);

        Http::assertNothingSent();
    }

    public function test_order_workspace_continues_when_radiumbox_lookup_fails(): void
    {
        Log::spy();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response(null, 500),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('RD3433380');

        $order->refresh();

        $this->assertNull($order->serial_number);
        $this->assertNull($order->device_model);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'RadiumBox order lookup failed.'
                    && ($context['order_id'] ?? null) === 'RD3433380';
            });
    }

    public function test_order_not_found_is_logged_and_does_not_block_workspace(): void
    {
        Log::spy();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 404,
                'message' => 'RD Order not found',
            ]),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD999999999',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('RD999999999');

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_duplicate_lookups_are_not_sent_during_the_same_request(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3433380',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $service = app(\App\Services\RadiumBox\RadiumBoxService::class);

        $service->enrichOrderForWorkspace($order);
        $service->enrichOrderForWorkspace($order->fresh());

        Http::assertSentCount(1);
    }
}
