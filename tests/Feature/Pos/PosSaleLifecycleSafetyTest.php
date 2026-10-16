<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleSerialExchangeService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosSaleLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private InventoryBranch $branch;

    private PosSaleService $sales;

    private PosSaleSerialExchangeService $exchanges;

    private StatutoryInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.location_series.enabled' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->exchanges = app(PosSaleSerialExchangeService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
    }

    public function test_exchange_is_blocked_when_invoice_cancelled_but_sale_still_completed(): void
    {
        [$sale, $invoice] = $this->saleWithSerials('LC-XCH-1', 'LC-OLD-1', 'LC-NEW-1', issue: true);
        $invoice->update(['status' => StatutoryInvoiceStatus::Cancelled]);

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'LC-OLD-1',
            toSerialNumber: 'LC-NEW-1',
            actor: $this->admin,
            reason: 'Should not swap with stale GST record',
            idempotencyKey: 'lc-xch-cancelled',
        );
    }

    public function test_exchange_then_cancel_reverses_replacement_serial(): void
    {
        [$sale] = $this->saleWithSerials('LC-CXL-1', 'LC-OLD-2', 'LC-NEW-2');

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'LC-OLD-2',
            toSerialNumber: 'LC-NEW-2',
            actor: $this->admin,
            reason: 'Swap before cancel',
            idempotencyKey: 'lc-xch-then-cancel',
        );

        $cancelled = $this->sales->cancelSale($sale->fresh(), $this->admin, 'Customer returned after swap');

        $replacement = InventorySerial::query()->where('serial_number', 'LC-NEW-2')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Cancelled, $cancelled->status);
        $this->assertSame(InventorySerialStatus::Available, $replacement->status);
    }

    public function test_exchange_then_return_reverses_replacement_serial(): void
    {
        [$sale] = $this->saleWithSerials('LC-RET-1', 'LC-OLD-3', 'LC-NEW-3');

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'LC-OLD-3',
            toSerialNumber: 'LC-NEW-3',
            actor: $this->admin,
            reason: 'Swap before return',
            idempotencyKey: 'lc-xch-then-return',
        );

        $returned = $this->sales->returnSale($sale->fresh(), $this->admin, 'Return after swap');

        $replacement = InventorySerial::query()->where('serial_number', 'LC-NEW-3')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Returned, $returned->status);
        $this->assertSame(InventorySerialStatus::Available, $replacement->status);
    }

    public function test_cancelled_sale_cannot_be_returned_again(): void
    {
        [$sale] = $this->saleWithSerials('LC-DBL-1', 'LC-OLD-4', 'LC-NEW-4');
        $this->sales->cancelSale($sale, $this->admin, 'First cancel');

        $this->expectException(ValidationException::class);
        $this->sales->returnSale($sale->fresh(), $this->admin, 'Second return');
    }

    public function test_cancelled_sale_cannot_be_exchanged(): void
    {
        [$sale] = $this->saleWithSerials('LC-DBL-1B', 'LC-OLD-4B', 'LC-NEW-4B');
        $this->sales->cancelSale($sale, $this->admin, 'First cancel');

        $this->expectException(ValidationException::class);
        $this->exchanges->exchange(
            sale: $sale->fresh(),
            fromSerialNumber: 'LC-OLD-4B',
            toSerialNumber: 'LC-NEW-4B',
            actor: $this->admin,
            reason: 'Exchange after cancel',
            idempotencyKey: 'lc-after-cancel',
        );
    }

    public function test_returned_sale_cannot_be_returned_again(): void
    {
        [$sale] = $this->saleWithSerials('LC-DBL-2', 'LC-OLD-5', 'LC-NEW-5');
        $this->sales->returnSale($sale, $this->admin, 'First return');

        $this->expectException(ValidationException::class);
        $this->sales->returnSale($sale->fresh(), $this->admin, 'Second return');
    }

    /**
     * @return array{0: \App\Models\InventorySale, 1: ?\App\Models\StatutoryInvoice}
     */
    private function saleWithSerials(string $marker, string $sold, string $replacement, bool $issue = false): array
    {
        $this->configureLocationSellerIdentity();
        config(['statutory_invoices.location_series.enabled' => $issue]);

        $product = InventoryProduct::query()->create([
            'sku' => 'SKU-'.$marker,
            'name' => 'Product '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, [$sold, $replacement], $this->admin);

        $phoneSuffix = str_pad((string) abs(crc32($marker)), 10, '0', STR_PAD_LEFT);
        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer '.$marker, 'phone' => '9'.$phoneSuffix],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$sold],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        $invoice = null;
        if ($issue) {
            $invoice = $this->invoices->issueFromPosSale($sale, $this->admin);
            $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);
        }

        return [$sale->fresh(['serials.serial']), $invoice];
    }
}
