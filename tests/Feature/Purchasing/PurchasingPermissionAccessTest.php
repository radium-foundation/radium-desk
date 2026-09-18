<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Support\Purchasing\PurchasingAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchasingPermissionAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const EXPECTED_PURCHASE_PERMISSIONS = [
        'PERMISSION_PURCHASE_VIEW' => 'purchase.view',
        'PERMISSION_PURCHASE_CREATE' => 'purchase.create',
        'PERMISSION_PURCHASE_EDIT' => 'purchase.edit',
        'PERMISSION_PURCHASE_RECEIVE' => 'purchase.receive',
        'PERMISSION_PURCHASE_INVOICE' => 'purchase.invoice',
        'PERMISSION_PURCHASE_PAYMENT' => 'purchase.payment',
        'PERMISSION_PURCHASE_VENDOR_MANAGE' => 'purchase.vendor.manage',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_purchase_permission_constants_exist_with_expected_strings(): void
    {
        foreach (self::EXPECTED_PURCHASE_PERMISSIONS as $constant => $expectedValue) {
            $this->assertTrue(
                defined(RolePermissionSeeder::class.'::'.$constant),
                sprintf('%s must be defined on RolePermissionSeeder.', $constant),
            );
            $this->assertSame(
                $expectedValue,
                constant(RolePermissionSeeder::class.'::'.$constant),
            );
        }
    }

    public function test_role_permission_seeder_registers_all_purchase_permissions(): void
    {
        foreach (self::EXPECTED_PURCHASE_PERMISSIONS as $expectedValue) {
            $this->assertTrue(
                Permission::query()->where('name', $expectedValue)->exists(),
                sprintf('Permission [%s] must be registered by RolePermissionSeeder.', $expectedValue),
            );
        }
    }

    public function test_purchasing_access_does_not_fatal_for_admin_user(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue(PurchasingAccess::allows($admin));
        $this->assertTrue(PurchasingAccess::allowsPermission(
            $admin,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ));
        $this->assertTrue(PurchasingAccess::allowsPermission(
            $admin,
            RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE,
        ));
    }

    public function test_admin_role_receives_all_purchasing_permissions_by_default(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        foreach (self::EXPECTED_PURCHASE_PERMISSIONS as $permission) {
            $this->assertTrue($admin->can($permission), sprintf('Admin must have [%s].', $permission));
        }
    }

    public function test_agent_without_purchase_view_cannot_access_purchasing(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertFalse(PurchasingAccess::allows($agent));
        $this->assertFalse(PurchasingAccess::allowsPermission(
            $agent,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ));

        $this->actingAs($agent)
            ->get(route('purchasing.purchase-orders.index'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('purchasing.purchase-orders.create'))
            ->assertForbidden();
    }

    public function test_purchase_order_create_route_does_not_fatal_when_constants_are_defined(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(RolePermissionSeeder::PERMISSION_PURCHASE_VIEW);

        $this->actingAs($viewer)
            ->get(route('purchasing.purchase-orders.create'))
            ->assertForbidden();
    }

    public function test_purchasing_permission_gates_remain_functional(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(RolePermissionSeeder::PERMISSION_PURCHASE_VIEW);

        $creator = User::factory()->create(['is_active' => true]);
        $creator->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ]);

        $vendorManager = User::factory()->create(['is_active' => true]);
        $vendorManager->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE,
        ]);

        $this->assertTrue(PurchasingAccess::allows($viewer));
        $this->assertFalse(PurchasingAccess::allowsPermission(
            $viewer,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ));

        $this->assertTrue(PurchasingAccess::allowsPermission(
            $creator,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ));
        $this->assertFalse(PurchasingAccess::allowsPermission(
            $creator,
            RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE,
        ));

        $this->assertTrue(PurchasingAccess::allowsPermission(
            $vendorManager,
            RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE,
        ));
        $this->assertFalse(PurchasingAccess::allowsPermission(
            $vendorManager,
            RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE,
        ));
    }
}
