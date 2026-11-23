<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreShipmentCancellationWorkspaceCtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'preship_cancellations.submit',
            'preship_cancellations.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_workspace_hides_preship_cancellation_cta_when_feature_disabled(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        Bus::fake();

        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $user->givePermissionTo('preship_cancellations.submit');

        $order = Order::query()->create([
            'order_id' => 'RBP-UAT-9100001',
            'product_name' => 'UAT Device',
            'device_model' => 'M1',
            'status' => 'active',
            'payment_amount' => 1500,
            'cashfree_payment_id' => 'cf_uat_psc_cta',
            'created_by' => $user->id,
        ]);

        $createUrl = route('orders.pre-shipment-cancellations.create', $order);

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee($createUrl, false)
            ->assertDontSee('bi-x-circle', false);

        $this->assertSame(0, PreShipmentCancellationRequest::query()->count());
        Bus::assertNothingDispatched();
    }
}
