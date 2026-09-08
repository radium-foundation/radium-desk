<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\User;
use App\Support\HardwareFulfilment\HardwareFulfilmentNavigation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_support_order_id_wins_over_source_id(): void
    {
        $deskOrder = $this->deskOrder('RDE901001');
        $bySupport = $this->fulfilment('RDE-OTHER-SOURCE', $deskOrder->id);
        $this->fulfilment('RDE901001', null);

        $resolved = HardwareFulfilmentNavigation::resolveForOrder($deskOrder);

        $this->assertNotNull($resolved);
        $this->assertSame($bySupport->id, $resolved->id);
    }

    public function test_falls_back_to_source_id_when_support_order_is_missing(): void
    {
        $deskOrder = $this->deskOrder('RDE901002');
        $bySource = $this->fulfilment('RDE901002', null);

        $resolved = HardwareFulfilmentNavigation::resolveForOrder($deskOrder);

        $this->assertNotNull($resolved);
        $this->assertSame($bySource->id, $resolved->id);
    }

    public function test_returns_null_when_no_fulfilment_exists(): void
    {
        $deskOrder = $this->deskOrder('RDE901003');

        $this->assertNull(HardwareFulfilmentNavigation::resolveForOrder($deskOrder));
        $this->assertNull(HardwareFulfilmentNavigation::urlFor($this->admin(), $deskOrder));
    }

    public function test_url_requires_fulfilment_access(): void
    {
        $deskOrder = $this->deskOrder('RDE901004');
        $fulfilment = $this->fulfilment('RDE901004', $deskOrder->id);
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertNull(HardwareFulfilmentNavigation::urlFor($agent, $deskOrder));
        $this->assertSame(
            route('inventory.hardware-fulfilments.show', $fulfilment),
            HardwareFulfilmentNavigation::urlFor($this->admin(), $deskOrder),
        );
    }

    public function test_url_is_hidden_when_branch_scope_would_deny_show(): void
    {
        $delhi = $this->branch('DELHI-RETAIL');
        $mumbai = $this->branch('MUMBAI');
        $deskOrder = $this->deskOrder('RDE901005');
        $fulfilment = $this->fulfilment('RDE901005', $deskOrder->id, $mumbai->id);

        $delhiOperator = User::factory()->create(['is_active' => true]);
        $delhiOperator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $delhiOperator->id,
            'branch_id' => $delhi->id,
        ]);

        $this->assertFalse(HardwareFulfilmentNavigation::userCanOpen($delhiOperator, $fulfilment));
        $this->assertNull(HardwareFulfilmentNavigation::urlFor($delhiOperator, $deskOrder));
        $this->assertNotNull(HardwareFulfilmentNavigation::urlFor($this->admin(), $deskOrder));
    }

    public function test_does_not_invent_an_id_or_create_a_record(): void
    {
        $deskOrder = $this->deskOrder('RIN901006');

        $this->assertNull(HardwareFulfilmentNavigation::resolveForOrder($deskOrder));
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function deskOrder(string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
        ]);
    }

    private function fulfilment(string $sourceId, ?int $supportOrderId, ?int $branchId = null): HardwareFulfilment
    {
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
            'support_order_id' => $supportOrderId,
        ]);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'support_order_id' => $supportOrderId,
            'fulfilment_branch_id' => $branchId,
            'ingested_at' => now(),
        ]);
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }
}
