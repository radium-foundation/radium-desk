<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Policies\ReturnExchangeCasePolicy;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReturnExchangeCaseBranchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryBranch $branchA;

    private InventoryBranch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_unassigned_agent_cannot_view_another_users_branchless_case(): void
    {
        $owner = $this->agentUser();
        $other = $this->agentUser();
        $case = $this->serviceCase($owner);
        $policy = app(ReturnExchangeCasePolicy::class);

        $this->assertFalse($policy->view($other, $case));
        $this->assertFalse(ReturnExchangeCaseBranchAccess::canView($other, $case));
    }

    public function test_unassigned_agent_can_view_own_branchless_case(): void
    {
        $agent = $this->agentUser();
        $case = $this->serviceCase($agent);
        $policy = app(ReturnExchangeCasePolicy::class);

        $this->assertTrue($policy->view($agent, $case));
    }

    public function test_unassigned_agent_index_scope_lists_only_own_cases(): void
    {
        $agentA = $this->agentUser();
        $agentB = $this->agentUser();
        $caseA = $this->serviceCase($agentA);
        $this->serviceCase($agentB);

        $visible = ReturnExchangeCaseBranchAccess::constrainVisible(
            ReturnExchangeCase::query(),
            $agentA,
        )->pluck('id')->all();

        $this->assertSame([$caseA->id], $visible);
    }

    public function test_central_admin_can_view_any_branch_case(): void
    {
        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = $this->agentUser();
        $case = ReturnExchangeCase::query()->create([
            'case_no' => 'RC-000099',
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder,
            'status' => ReturnExchangeCaseStatus::Submitted,
            'branch_id' => $this->branchB->id,
            'reason' => 'Synthetic branch case',
            'requested_by' => $agent->id,
        ]);

        $this->assertTrue(ReturnExchangeCaseBranchAccess::canView($central, $case));
    }

    public function test_branch_assigned_user_sees_only_assigned_branch_cases(): void
    {
        $branchUser = User::factory()->create(['is_active' => true]);
        $branchUser->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $branchUser->id,
            'branch_id' => $this->branchA->id,
        ]);

        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $caseA = ReturnExchangeCase::query()->create([
            'case_no' => 'RC-000101',
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder,
            'status' => ReturnExchangeCaseStatus::Submitted,
            'branch_id' => $this->branchA->id,
            'reason' => 'Branch A',
            'requested_by' => $central->id,
        ]);
        $caseB = ReturnExchangeCase::query()->create([
            'case_no' => 'RC-000102',
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder,
            'status' => ReturnExchangeCaseStatus::Submitted,
            'branch_id' => $this->branchB->id,
            'reason' => 'Branch B',
            'requested_by' => $central->id,
        ]);

        $this->assertTrue(ReturnExchangeCaseBranchAccess::canView($branchUser, $caseA));
        $this->assertFalse(ReturnExchangeCaseBranchAccess::canView($branchUser, $caseB));
    }

    public function test_unassigned_agent_cannot_create_pos_case_for_any_branch(): void
    {
        $setupAdmin = User::factory()->create(['is_active' => true]);
        $setupAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $stock = app(\App\Services\Inventory\InventoryStockService::class);
        $sales = app(\App\Services\Inventory\PosSaleService::class);
        $product = \App\Models\InventoryProduct::query()->create([
            'sku' => 'RC-AUTH-1',
            'name' => 'Auth device',
            'gst_percentage' => 18,
            'unit_price' => 5000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $stock->stockInSerialized($product, $this->branchA, ['AUTH-1'], $setupAdmin);
        $sale = $sales->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900888'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['AUTH-1']]],
            paymentMethod: 'Cash',
            actor: $setupAdmin,
        );

        $this->expectException(HttpException::class);

        app(ReturnExchangeCaseIntakeService::class)->create($this->agentUser(), [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Should fail',
            'lines' => [['description' => 'Device', 'qty' => 1]],
        ]);
    }

    public function test_agent_role_does_not_receive_central_permission(): void
    {
        $agent = $this->agentUser();

        $this->assertFalse($agent->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_CENTRAL));
    }

    private function agentUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function serviceCase(User $requester): ReturnExchangeCase
    {
        $order = Order::query()->create([
            'order_id' => 'RB'.random_int(10000, 99999),
            'customer_name' => 'Test',
            'status' => 'active',
        ]);

        return app(ReturnExchangeCaseIntakeService::class)->create($requester, [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'Service return intake',
            'lines' => [['description' => 'Plan', 'qty' => 1]],
        ]);
    }
}
