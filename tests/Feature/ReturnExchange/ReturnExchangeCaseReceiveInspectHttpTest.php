<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseReceipt;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCaseReceiveInspectHttpTest extends ReturnExchangeCaseHttpTestCase
{
    public function test_branch_receiver_can_receive_via_http_and_serial_stays_non_sellable(): void
    {
        [$case, $line, $serialNo, $receiver] = $this->posCaseReadyForReceipt($this->branchA, 'UI-SERIAL-1', 'UI-PROD-1');

        $this->actingAs($receiver)
            ->get(route('return-cases.show', $case))
            ->assertOk()
            ->assertSee('Receive goods');

        $response = $this->actingAs($receiver)->post(route('return-cases.receive', $case), [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => $serialNo,
            'qty_received' => 1,
        ]);

        $response->assertRedirect(route('return-cases.show', $case));
        $response->assertSessionHas('status');

        $serial = ReturnExchangeCaseReceipt::query()->where('return_exchange_case_id', $case->id)->firstOrFail()->serial;
        $this->assertSame(InventorySerialStatus::Returned, $serial->status);
        $this->assertFalse($serial->status->isAssignable());
        $this->assertSame(0, (int) $serial->product->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
    }

    public function test_branch_receiver_can_inspect_via_http_without_making_serial_available(): void
    {
        [$case, $line, $serialNo, $receiver] = $this->posCaseReadyForReceipt($this->branchA, 'UI-SERIAL-2', 'UI-PROD-2');

        $this->actingAs($receiver)->post(route('return-cases.receive', $case), [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => $serialNo,
        ])->assertRedirect();

        $receipt = ReturnExchangeCaseReceipt::query()->where('return_exchange_case_id', $case->id)->firstOrFail();

        $this->actingAs($receiver)
            ->get(route('return-cases.show', $case))
            ->assertOk()
            ->assertSee('Inspect received goods');

        $this->actingAs($receiver)->post(route('return-cases.receipts.inspect', [$case, $receipt]), [
            'product_match_passed' => '1',
            'device_operates_ok' => '1',
            'accessories_verified' => '1',
            'repair_hold' => '0',
            'mark_damaged' => '0',
            'internal_notes' => 'Looks good internally.',
        ])->assertRedirect(route('return-cases.show', $case));

        $serial = $receipt->fresh()->serial;
        $this->assertSame(InventorySerialStatus::Returned, $serial->status);
        $this->assertFalse($serial->status->isAssignable());
        $this->assertSame(ReturnExchangeCaseStatus::Inspected, $case->fresh()->status);
    }

    public function test_wrong_branch_receiver_is_denied_on_receive(): void
    {
        [$case, $line, $serialNo] = $this->posCaseReadyForReceipt($this->branchA, 'UI-SERIAL-3', 'UI-PROD-3');
        unset($serialNo);
        $otherBranchReceiver = $this->hardwareReceiver($this->branchB->id);

        $this->actingAs($otherBranchReceiver)->post(route('return-cases.receive', $case), [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'UI-SERIAL-3',
        ])->assertForbidden();

        $this->assertSame(0, ReturnExchangeCaseReceipt::query()->where('return_exchange_case_id', $case->id)->count());
    }

    public function test_agent_without_receive_permission_is_denied(): void
    {
        $agent = $this->agentUser();
        $order = $this->createOrder();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($agent, [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'Agent-owned service case',
            'lines' => [['description' => 'Service item', 'qty' => 1]],
        ]);
        $line = $case->lines()->firstOrFail();

        $this->actingAs($agent)->get(route('return-cases.show', $case))->assertOk();
        $this->actingAs($agent)->post(route('return-cases.receive', $case), [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ])->assertForbidden();
    }

    public function test_closed_case_cannot_receive_via_http(): void
    {
        [$case, $line, $serialNo, $receiver] = $this->posCaseReadyForReceipt($this->branchA, 'UI-SERIAL-5', 'UI-PROD-5');
        $case->update(['status' => ReturnExchangeCaseStatus::Closed]);

        $this->actingAs($receiver)->post(route('return-cases.receive', $case), [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => $serialNo,
        ])->assertSessionHasErrors();
    }

    /**
     * @return array{0: ReturnExchangeCase, 1: \App\Models\ReturnExchangeCaseLine, 2: string, 3: User}
     */
    private function posCaseReadyForReceipt(\App\Models\InventoryBranch $branch, string $serialNo, string $sku): array
    {
        $setup = $this->centralAdmin();
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'UI receive device',
            'gst_percentage' => 18,
            'unit_price' => 5000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, [$serialNo], $setup);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Buyer', 'phone' => '9999900888'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => [$serialNo]]],
            paymentMethod: 'Cash',
            actor: $setup,
        );

        $case = app(ReturnExchangeCaseIntakeService::class)->create($setup, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'branch_id' => $branch->id,
            'reason' => 'HTTP UI test',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'serial_number' => $serialNo,
                'inventory_sale_line_id' => $sale->lines()->value('id'),
            ]],
        ]);
        $line = $case->lines()->firstOrFail();
        $receiver = $this->hardwareReceiver($branch->id);

        return [$case, $line, $serialNo, $receiver];
    }

    private function hardwareReceiver(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }
}
