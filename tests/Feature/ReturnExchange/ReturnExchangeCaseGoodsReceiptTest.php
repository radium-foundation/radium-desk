<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReturnExchangeCaseGoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private InventoryBranch $branch;

    private ReturnExchangeCaseGoodsReceiptService $receipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = InventoryBranch::query()->create([
            'code' => 'RCV-HQ',
            'name' => 'Receive HQ',
            'is_active' => true,
        ]);

        $this->receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
    }

    public function test_receive_moves_sold_serial_to_returned_without_increasing_sellable_qty(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $availableBefore = (int) $serial->product->balances()->where('branch_id', $this->branch->id)->value('available_qty');
        $saleSnapshot = $sale->fresh()->toArray();
        $movementCount = InventoryMovement::query()->count();

        $receipt = $this->receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);

        $serial->refresh();
        $this->assertSame(InventorySerialStatus::Returned, $serial->status);
        $this->assertFalse($serial->status->isAssignable());
        $this->assertSame($availableBefore, (int) $serial->product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
        $this->assertSame($saleSnapshot, $sale->fresh()->toArray());
        $this->assertSame(1, $sale->serials()->count());
        $this->assertGreaterThan($movementCount, InventoryMovement::query()->count());
        $this->assertSame(ReturnExchangeCaseStatus::Received, $case->fresh()->status);
        $this->assertNotNull($receipt->received_at);
    }

    public function test_inspection_keeps_serial_returned_and_non_sellable(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $receipt = $this->receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);

        $this->receipts->inspect($case, $receipt, $receiver, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
            'repair_hold' => false,
            'mark_damaged' => false,
        ]);

        $this->assertSame(InventorySerialStatus::Returned, $serial->fresh()->status);
        $this->assertFalse($serial->fresh()->status->isAssignable());
        $this->assertSame(ReturnExchangeCaseStatus::Inspected, $case->fresh()->status);
        $this->assertSame($sale->fresh()->toArray(), $sale->fresh()->toArray());
    }

    public function test_inspection_can_mark_damaged_without_making_available(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial('RCV-SERIAL-2', 'RCV-PROD-2');
        $receipt = $this->receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-2',
        ]);

        $this->receipts->inspect($case, $receipt, $receiver, [
            'product_match_passed' => true,
            'device_operates_ok' => false,
            'accessories_verified' => false,
            'repair_hold' => true,
            'mark_damaged' => true,
        ]);

        $this->assertSame(InventorySerialStatus::Damaged, $serial->fresh()->status);
        $this->assertFalse($serial->fresh()->status->isAssignable());
    }

    public function test_service_case_receipt_records_evidence_without_inventory_movement(): void
    {
        $central = $this->centralAdmin();
        $receiver = $this->branchReceiver($this->branch->id);
        $order = Order::query()->create([
            'order_id' => 'RB'.random_int(10000, 99999),
            'customer_name' => 'Svc',
            'status' => 'active',
        ]);
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'Service return',
            'lines' => [['description' => 'Service plan', 'qty' => 1]],
        ]);
        $line = $case->lines()->firstOrFail();
        $movements = InventoryMovement::query()->count();

        $receipt = $this->receipts->receive($case, $central, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ]);

        $this->assertNull($receipt->inventory_serial_id);
        $this->assertSame($movements, InventoryMovement::query()->count());
    }

    public function test_duplicate_receipt_is_idempotent(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $payload = [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
            'idempotency_key' => 'recv-once',
        ];
        $first = $this->receipts->receive($case, $receiver, $payload);
        $second = $this->receipts->receive($case->fresh(), $receiver, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $case->receipts()->count());
    }

    public function test_unassigned_agent_cannot_receive_branch_case(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_RETURNS_CASES_RECEIVE);

        $this->expectException(HttpException::class);
        $this->receipts->receive($case, $agent, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);
    }

    public function test_branch_receiver_cannot_receive_for_other_branch(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $otherBranch = InventoryBranch::query()->create([
            'code' => 'RCV-OTHER',
            'name' => 'Other',
            'is_active' => true,
        ]);
        $wrongReceiver = $this->branchReceiver($otherBranch->id);

        $this->expectException(HttpException::class);
        $this->receipts->receive($case, $wrongReceiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);
    }

    public function test_same_serial_cannot_be_received_on_two_open_cases(): void
    {
        [$sale, $serial, $case, $line, $receiver] = $this->posCaseWithSoldSerial();
        $central = $this->centralAdmin();
        $case2 = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Second case',
            'lines' => [[
                'description' => 'Device',
                'qty' => 1,
                'serial_number' => 'RCV-SERIAL-1',
            ]],
        ]);
        $line2 = $case2->lines()->firstOrFail();

        $this->receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);

        $this->expectException(ValidationException::class);
        $this->receipts->receive($case2, $receiver, [
            'return_exchange_case_line_id' => $line2->id,
            'serial_number_scanned' => 'RCV-SERIAL-1',
        ]);
    }

    /**
     * @return array{0: InventorySale, 1: \App\Models\InventorySerial, 2: \App\Models\ReturnExchangeCase, 3: \App\Models\ReturnExchangeCaseLine, 4: User}
     */
    private function posCaseWithSoldSerial(string $serialNo = 'RCV-SERIAL-1', string $sku = 'RCV-PROD'): array
    {
        $setup = User::factory()->create(['is_active' => true]);
        $setup->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Receive test device',
            'gst_percentage' => 18,
            'unit_price' => 8000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $stock->stockInSerialized($product, $this->branch, [$serialNo], $setup);
        $sale = $sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer', 'phone' => '9999900666'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => [$serialNo]]],
            paymentMethod: 'Cash',
            actor: $setup,
        );
        $serial = $sale->serials()->firstOrFail()->serial;
        $case = app(ReturnExchangeCaseIntakeService::class)->create($setup, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Return intake',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'serial_number' => $serialNo,
                'inventory_sale_line_id' => $sale->lines()->value('id'),
            ]],
        ]);
        $line = $case->lines()->firstOrFail();
        $receiver = $this->branchReceiver($this->branch->id);

        return [$sale, $serial, $case, $line, $receiver];
    }

    private function branchReceiver(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }

    private function centralAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}
