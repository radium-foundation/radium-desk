<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCaseCentralQueueTest extends ReturnExchangeCaseHttpTestCase
{
    public function test_operations_admin_role_can_access_central_queue(): void
    {
        $opsAdmin = User::factory()->create(['is_active' => true]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $case = $this->branchTaggedCase($this->branchB, $this->centralAdmin());

        $this->actingAs($opsAdmin)
            ->get(route('return-cases.central-queue'))
            ->assertOk()
            ->assertSee($case->case_no);
    }

    public function test_finance_viewer_without_central_permission_cannot_access_queue(): void
    {
        $financeViewer = User::factory()->create(['is_active' => true]);
        $financeViewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_FINANCE_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
        ]);

        $this->branchTaggedCase($this->branchA, $this->centralAdmin());

        $this->assertFalse($financeViewer->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_CENTRAL));
        $this->actingAs($financeViewer)->get(route('return-cases.central-queue'))->assertForbidden();
    }

    public function test_central_user_can_list_cross_branch_cases_and_filter_by_branch(): void
    {
        $central = $this->centralAdmin();
        $caseA = $this->branchTaggedCase($this->branchA, $central);
        $caseB = $this->branchTaggedCase($this->branchB, $central);

        $this->actingAs($central)
            ->get(route('return-cases.central-queue'))
            ->assertOk()
            ->assertSee($caseA->case_no)
            ->assertSee($caseB->case_no)
            ->assertSee('Read-only triage');

        $filtered = $this->actingAs($central)->get(route('return-cases.central-queue', [
            'branch_id' => $this->branchA->id,
        ]));
        $filtered->assertOk();
        $filtered->assertSee($caseA->case_no);
        $filtered->assertDontSee($caseB->case_no);
    }

    public function test_agent_branch_user_and_unassigned_non_central_cannot_access_central_queue(): void
    {
        $central = $this->centralAdmin();
        $this->branchTaggedCase($this->branchA, $central);

        $this->actingAs($this->agentUser())->get(route('return-cases.central-queue'))->assertForbidden();

        $branchUser = User::factory()->create(['is_active' => true]);
        $branchUser->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_RECEIVE,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $branchUser->id,
            'branch_id' => $this->branchA->id,
        ]);
        $this->actingAs($branchUser)->get(route('return-cases.central-queue'))->assertForbidden();

        $agentWithReceiveOnly = $this->agentUser();
        $agentWithReceiveOnly->givePermissionTo(RolePermissionSeeder::PERMISSION_RETURNS_CASES_RECEIVE);
        $this->actingAs($agentWithReceiveOnly)->get(route('return-cases.central-queue'))->assertForbidden();
    }

    public function test_central_queue_get_is_read_only(): void
    {
        $central = $this->centralAdmin();
        $setup = $central;
        $product = InventoryProduct::query()->create([
            'sku' => 'CQ-PROD',
            'name' => 'Queue read-only device',
            'gst_percentage' => 18,
            'unit_price' => 4000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branchA, ['CQ-SERIAL'], $setup);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900991'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['CQ-SERIAL']]],
            paymentMethod: 'Cash',
            actor: $setup,
        );
        $case = app(ReturnExchangeCaseIntakeService::class)->create($setup, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'branch_id' => $this->branchA->id,
            'reason' => 'Queue read-only',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'serial_number' => 'CQ-SERIAL',
                'inventory_sale_line_id' => $sale->lines()->value('id'),
            ]],
        ]);
        $line = $case->lines()->firstOrFail();
        $receiver = User::factory()->create(['is_active' => true]);
        $receiver->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $receiver->id,
            'branch_id' => $this->branchA->id,
        ]);
        app(ReturnExchangeCaseGoodsReceiptService::class)->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'CQ-SERIAL',
        ]);

        $statusBefore = $case->fresh()->status;
        $serial = $sale->serials()->firstOrFail()->serial;
        $serialStatusBefore = $serial->fresh()->status;
        $movementsBefore = InventoryMovement::query()->count();
        $availableBefore = (int) $product->balances()->where('branch_id', $this->branchA->id)->value('available_qty');

        $this->actingAs($central)->get(route('return-cases.central-queue'))->assertOk()->assertSee($case->case_no);
        $this->actingAs($central)->get(route('return-cases.central-queue', [
            'receipt_state' => 'awaiting_inspection',
            'status' => ReturnExchangeCaseStatus::Received->value,
        ]))->assertOk();

        $this->assertSame($statusBefore, $case->fresh()->status);
        $this->assertSame($serialStatusBefore, $serial->fresh()->status);
        $this->assertFalse($serial->fresh()->status->isAssignable());
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertSame($availableBefore, (int) $product->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
    }

    public function test_branch_staff_existing_show_and_receive_remain_available(): void
    {
        $central = $this->centralAdmin();
        $case = $this->branchTaggedCase($this->branchA, $central);
        $branchUser = User::factory()->create(['is_active' => true]);
        $branchUser->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $branchUser->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($branchUser)->get(route('return-cases.show', $case))->assertOk();
        $this->actingAs($branchUser)->get(route('return-cases.central-queue'))->assertForbidden();
    }

    private function branchTaggedCase(\App\Models\InventoryBranch $branch, User $requester): ReturnExchangeCase
    {
        return ReturnExchangeCase::query()->create([
            'case_no' => 'RC-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder,
            'status' => ReturnExchangeCaseStatus::Submitted,
            'branch_id' => $branch->id,
            'reason' => 'Central queue test',
            'requested_by' => $requester->id,
        ]);
    }
}
