<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCaseReceipt;
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
use Tests\TestCase;

class ReturnExchangeCaseSerialAdjustmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private InventoryBranch $branch;

    private InventoryStockService $stock;

    private User $adjuster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = InventoryBranch::query()->create([
            'code' => 'ADJ-HQ',
            'name' => 'Adjustment HQ',
            'is_active' => true,
        ]);

        $this->stock = app(InventoryStockService::class);
        $this->adjuster = User::factory()->create(['is_active' => true]);
        $this->adjuster->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_return_case_returned_serial_cannot_be_adjusted_to_available(): void
    {
        [$serial, $receiver] = $this->receivedCaseSerial('ADJ-SERIAL-R', 'ADJ-PROD-R');

        $this->assertSame(InventorySerialStatus::Returned, $serial->fresh()->status);
        $adjustmentsBefore = InventoryAdjustment::query()->count();
        $movementsBefore = InventoryMovement::query()->where('type', InventoryMovementType::Adjustment)->count();
        $availableBefore = (int) $serial->product->balances()->where('branch_id', $this->branch->id)->value('available_qty');

        try {
            $this->stock->adjustSerialStatus(
                $serial->fresh(),
                InventorySerialStatus::Available,
                InventoryAdjustmentReason::Other,
                $this->adjuster,
            );
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(InventorySerialStatus::Returned, $serial->fresh()->status);
        $this->assertSame($adjustmentsBefore, InventoryAdjustment::query()->count());
        $this->assertSame($movementsBefore, InventoryMovement::query()->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertSame(
            $availableBefore,
            (int) $serial->product->balances()->where('branch_id', $this->branch->id)->value('available_qty'),
        );
    }

    public function test_return_case_damaged_serial_cannot_be_adjusted_to_available(): void
    {
        [$serial, $receiver] = $this->receivedCaseSerial('ADJ-SERIAL-D', 'ADJ-PROD-D');
        $receipt = ReturnExchangeCaseReceipt::query()
            ->where('inventory_serial_id', $serial->id)
            ->firstOrFail();
        $case = $receipt->case;

        app(ReturnExchangeCaseGoodsReceiptService::class)->inspect($case, $receipt, $receiver, [
            'product_match_passed' => true,
            'device_operates_ok' => false,
            'accessories_verified' => false,
            'repair_hold' => true,
            'mark_damaged' => true,
        ]);

        $this->assertSame(InventorySerialStatus::Damaged, $serial->fresh()->status);

        $this->expectException(ValidationException::class);
        $this->stock->adjustSerialStatus(
            $serial->fresh(),
            InventorySerialStatus::Available,
            InventoryAdjustmentReason::Other,
            $this->adjuster,
        );
    }

    public function test_unrelated_returned_serial_can_still_be_adjusted_to_available(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'ADJ-UNREL',
            'name' => 'Unrelated device',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $serial = $this->stock->stockInSerialized($product, $this->branch, ['ADJ-UNREL-1'], $this->adjuster)[0];

        $this->stock->adjustSerialStatus(
            $serial,
            InventorySerialStatus::Returned,
            InventoryAdjustmentReason::Other,
            $this->adjuster,
        );

        $this->assertSame(InventorySerialStatus::Returned, $serial->fresh()->status);

        $adjustment = $this->stock->adjustSerialStatus(
            $serial->fresh(),
            InventorySerialStatus::Available,
            InventoryAdjustmentReason::Other,
            $this->adjuster,
        );

        $this->assertSame(InventorySerialStatus::Available, $serial->fresh()->status);
        $this->assertTrue(InventoryMovement::query()->where('adjustment_id', $adjustment->id)->exists());
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));
    }

    /**
     * @return array{0: \App\Models\InventorySerial, 1: User}
     */
    private function receivedCaseSerial(string $serialNo, string $sku): array
    {
        $setup = User::factory()->create(['is_active' => true]);
        $setup->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $receiver = User::factory()->create(['is_active' => true]);
        $receiver->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $receiver->id,
            'branch_id' => $this->branch->id,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Guard test device',
            'gst_percentage' => 18,
            'unit_price' => 8000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($product, $this->branch, [$serialNo], $setup);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer', 'phone' => '9999900777'],
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

        app(ReturnExchangeCaseGoodsReceiptService::class)->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => $serialNo,
        ]);

        return [$serial->fresh(), $receiver];
    }
}
