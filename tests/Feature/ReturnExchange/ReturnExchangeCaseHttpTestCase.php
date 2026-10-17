<?php

namespace Tests\Feature\ReturnExchange;

use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * HTTP feature tests for return-case routes (Blade layouts require Vite stubbing in CI/worktrees).
 */
abstract class ReturnExchangeCaseHttpTestCase extends TestCase
{
    use RefreshDatabase;

    protected InventoryBranch $branchA;

    protected InventoryBranch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branchA = InventoryBranch::query()->create([
            'code' => 'BR-A',
            'name' => 'Branch A',
            'is_active' => true,
        ]);
        $this->branchB = InventoryBranch::query()->create([
            'code' => 'BR-B',
            'name' => 'Branch B',
            'is_active' => true,
        ]);
    }

    protected function agentUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    protected function centralAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_id' => 'RB'.random_int(10000, 99999),
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '9999900999',
            'payment_amount' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
