<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCaseRouteAuthorizationTest extends ReturnExchangeCaseHttpTestCase
{
    public function test_central_admin_can_show_cross_branch_case_and_open_create(): void
    {
        $central = $this->centralAdmin();
        $case = $this->branchTaggedCase($this->branchB, $central);

        $this->actingAs($central)->get(route('return-cases.show', $case))->assertOk();
        $this->actingAs($central)->get(route('return-cases.create'))->assertOk();
        $this->actingAs($central)->get(route('return-cases.index'))->assertOk()->assertSee($case->case_no);
    }

    public function test_branch_assigned_user_can_show_own_branch_case_but_not_other_branch(): void
    {
        $branchUser = $this->branchScopedUser($this->branchA->id);
        $central = $this->centralAdmin();

        $caseA = $this->branchTaggedCase($this->branchA, $central);
        $caseB = $this->branchTaggedCase($this->branchB, $central);

        $this->actingAs($branchUser)->get(route('return-cases.show', $caseA))->assertOk();
        $this->actingAs($branchUser)->get(route('return-cases.show', $caseB))->assertForbidden();
    }

    public function test_branch_assigned_user_index_excludes_other_branch_cases(): void
    {
        $branchUser = $this->branchScopedUser($this->branchA->id);
        $central = $this->centralAdmin();

        $caseA = $this->branchTaggedCase($this->branchA, $central);
        $caseB = $this->branchTaggedCase($this->branchB, $central);

        $response = $this->actingAs($branchUser)->get(route('return-cases.index'));
        $response->assertOk();
        $response->assertSee($caseA->case_no);
        $response->assertDontSee($caseB->case_no);
    }

    public function test_unassigned_agent_can_show_own_branchless_case_but_not_another_agents(): void
    {
        $owner = $this->agentUser();
        $other = $this->agentUser();
        $ownCase = $this->serviceCaseViaHttp($owner);
        $otherCase = $this->serviceCaseViaHttp($other);

        $this->actingAs($owner)->get(route('return-cases.show', $ownCase))->assertOk();
        $this->actingAs($owner)->get(route('return-cases.show', $otherCase))->assertForbidden();
    }

    public function test_unassigned_agent_index_lists_only_own_cases(): void
    {
        $agentA = $this->agentUser();
        $agentB = $this->agentUser();
        $caseA = $this->serviceCaseViaHttp($agentA);
        $caseB = $this->serviceCaseViaHttp($agentB);

        $response = $this->actingAs($agentA)->get(route('return-cases.index'));
        $response->assertOk();
        $response->assertSee($caseA->case_no);
        $response->assertDontSee($caseB->case_no);
    }

    public function test_agent_can_store_branchless_service_case_and_is_redirected_to_show(): void
    {
        $agent = $this->agentUser();
        $order = $this->createOrder();

        $response = $this->actingAs($agent)->post(route('return-cases.store'), [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'HTTP service intake',
            'lines' => [['description' => 'Plan', 'qty' => 1]],
        ]);

        $case = ReturnExchangeCase::query()->where('requested_by', $agent->id)->firstOrFail();
        $response->assertRedirect(route('return-cases.show', $case));
        $this->actingAs($agent)->get(route('return-cases.show', $case))->assertOk();
    }

    public function test_unassigned_agent_cannot_store_pos_case_for_another_branch(): void
    {
        $setupAdmin = $this->centralAdmin();
        $product = \App\Models\InventoryProduct::query()->create([
            'sku' => 'RC-HTTP-1',
            'name' => 'HTTP auth device',
            'gst_percentage' => 18,
            'unit_price' => 5000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branchA, ['HTTP-1'], $setupAdmin);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900777'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['HTTP-1']]],
            paymentMethod: 'Cash',
            actor: $setupAdmin,
        );

        $agent = $this->agentUser();

        $this->actingAs($agent)->post(route('return-cases.store'), [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Should be denied',
            'lines' => [['description' => 'Device', 'qty' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, ReturnExchangeCase::query()->where('inventory_sale_id', $sale->id)->count());
    }

    public function test_employee_without_returns_permission_cannot_open_create(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->actingAs($user)->get(route('return-cases.create'))->assertForbidden();
        $this->actingAs($user)->get(route('return-cases.index'))->assertForbidden();
    }

    private function branchScopedUser(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }

    private function branchTaggedCase(\App\Models\InventoryBranch $branch, User $requester): ReturnExchangeCase
    {
        return ReturnExchangeCase::query()->create([
            'case_no' => 'RC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder,
            'status' => ReturnExchangeCaseStatus::Submitted,
            'branch_id' => $branch->id,
            'reason' => 'Synthetic for HTTP auth',
            'requested_by' => $requester->id,
        ]);
    }

    private function serviceCaseViaHttp(User $requester): ReturnExchangeCase
    {
        $order = $this->createOrder();

        return app(ReturnExchangeCaseIntakeService::class)->create($requester, [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'Service case',
            'lines' => [['description' => 'Item', 'qty' => 1]],
        ]);
    }
}
