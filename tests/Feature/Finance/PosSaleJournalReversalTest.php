<?php

namespace Tests\Feature\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceSetting;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Finance\AccountBalanceReadModel;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Finance\PosSaleJournalService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceChartOfAccountsSeeder;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosSaleJournalReversalTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private AccountBalanceReadModel $balances;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->balances = app(AccountBalanceReadModel::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'FIN',
            'name' => 'Finance Counter',
            'is_active' => true,
        ]);
    }

    public function test_cancel_keeps_the_original_journal_and_posts_a_reversing_entry(): void
    {
        $cashId = $this->accountId(FinanceChartOfAccountsSeeder::CODE_CASH_ON_HAND);
        $revenueId = $this->accountId(FinanceChartOfAccountsSeeder::CODE_SALES_INCOME);
        $cashBefore = $this->balances->compute($cashId);
        $revenueBefore = $this->balances->compute($revenueId);

        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->branch, ['FIN-CAN-1'], $this->actor);
        $sale = $this->completeSerializedSale('FIN-CAN-1', 'Cash', '9999910001');

        $this->assertSame(InventoryFinanceHandoffStatus::Posted, $sale->finance_handoff_status);
        $originalJournalId = $sale->finance_journal_id;
        $this->assertNotNull($originalJournalId);
        $this->assertEqualsWithDelta($cashBefore + (float) $sale->total, $this->balances->compute($cashId), 0.001);
        $this->assertEqualsWithDelta($revenueBefore + (float) $sale->total, $this->balances->compute($revenueId), 0.001);

        $cancelled = $this->sales->cancelSale($sale, $this->actor, 'Customer changed mind');

        $this->assertSame(InventorySaleStatus::Cancelled, $cancelled->status);
        $this->assertSame($originalJournalId, $cancelled->finance_journal_id);
        $this->assertSame(InventoryFinanceHandoffStatus::Reversed, $cancelled->finance_handoff_status);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'FIN-CAN-1')->value('status'));

        $reverse = FinanceJournal::query()
            ->where('idempotency_key', 'pos_sale:reverse:'.$sale->id.':'.$originalJournalId)
            ->with('lines')
            ->first();

        $this->assertNotNull($reverse);
        $this->assertSame(FinanceJournalSourceType::PosSale, $reverse->source_type);
        $this->assertSame($sale->id, $reverse->source_id);
        $this->assertSame($sale->total, $reverse->totalDebits());
        $this->assertSame($sale->total, $reverse->totalCredits());
        $this->assertEqualsWithDelta($cashBefore, $this->balances->compute($cashId), 0.001);
        $this->assertEqualsWithDelta($revenueBefore, $this->balances->compute($revenueId), 0.001);
        $this->assertSame(2, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale)->count());
    }

    public function test_return_reverses_bank_clearing_for_a_card_sale(): void
    {
        $bankId = $this->accountId(FinanceChartOfAccountsSeeder::CODE_BANK_CLEARING);
        $before = $this->balances->compute($bankId);

        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-FIN-RET',
            'name' => 'Return cable finance',
            'gst_percentage' => 18,
            'unit_price' => 80,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->branch, 3, $this->actor);
        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Card Customer', 'phone' => '9999910002'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
            paymentMethod: 'Card',
            actor: $this->actor,
        );

        $this->assertSame(InventoryFinanceHandoffStatus::Posted, $sale->finance_handoff_status);
        $this->assertEqualsWithDelta($before + (float) $sale->total, $this->balances->compute($bankId), 0.001);

        $this->sales->returnSale($sale, $this->actor, 'Defective');

        $fresh = $sale->fresh();
        $this->assertSame(InventorySaleStatus::Returned, $fresh->status);
        $this->assertSame(InventoryFinanceHandoffStatus::Reversed, $fresh->finance_handoff_status);
        $this->assertEqualsWithDelta($before, $this->balances->compute($bankId), 0.001);
        $this->assertTrue(
            FinanceJournal::query()
                ->where('idempotency_key', 'pos_sale:reverse:'.$sale->id.':'.$sale->finance_journal_id)
                ->exists()
        );
    }

    public function test_cancel_is_a_finance_noop_when_ledger_posting_was_skipped(): void
    {
        FinanceSetting::putValue(FinanceSettingsService::KEY_LEDGER_POSTING_ENABLED, '0');

        $product = $this->serializedProduct('MFS-SKIP', 'Skip scanner');
        $this->stock->stockInSerialized($product, $this->branch, ['FIN-SKIP-1'], $this->actor);
        $sale = $this->completeSerializedSale('FIN-SKIP-1', 'Cash', '9999910003', 'MFS-SKIP');

        $this->assertSame(InventoryFinanceHandoffStatus::Skipped, $sale->finance_handoff_status);
        $this->assertNull($sale->finance_journal_id);

        $cancelled = $this->sales->cancelSale($sale, $this->actor, 'Walked away');

        $this->assertSame(InventorySaleStatus::Cancelled, $cancelled->status);
        $this->assertSame(InventoryFinanceHandoffStatus::Skipped, $cancelled->finance_handoff_status);
        $this->assertSame(0, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale)->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'FIN-SKIP-1')->value('status'));
    }

    public function test_cancel_rolls_back_stock_when_the_original_journal_is_missing(): void
    {
        $product = $this->serializedProduct('MFS-MISS', 'Missing journal scanner');
        $this->stock->stockInSerialized($product, $this->branch, ['FIN-MISS-1'], $this->actor);
        $sale = $this->completeSerializedSale('FIN-MISS-1', 'Cash', '9999910004', 'MFS-MISS');

        $this->assertNotNull($sale->finance_journal_id);
        FinanceJournal::query()->whereKey($sale->finance_journal_id)->delete();

        try {
            $this->sales->cancelSale($sale, $this->actor, 'Should fail closed');
            $this->fail('Expected cancel to fail closed when the original journal is missing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('finance', $exception->errors());
        }

        $this->assertSame(InventorySaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'FIN-MISS-1')->value('status'));
    }

    public function test_reverse_is_idempotent_for_the_same_sale_journal(): void
    {
        $product = $this->serializedProduct('MFS-IDEM-REV', 'Idem reverse scanner');
        $this->stock->stockInSerialized($product, $this->branch, ['FIN-IDEM-1'], $this->actor);
        $sale = $this->completeSerializedSale('FIN-IDEM-1', 'UPI', '9999910005', 'MFS-IDEM-REV');

        $first = app(PosSaleJournalService::class)
            ->reverseForSale($sale, $this->actor);
        $second = app(PosSaleJournalService::class)
            ->reverseForSale($sale->fresh(), $this->actor);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(2, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale)->count());
        $this->assertSame(InventoryFinanceHandoffStatus::Reversed, $sale->fresh()->finance_handoff_status);
    }

    private function serializedProduct(string $sku = 'MFS-FIN', string $name = 'Finance scanner'): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    private function completeSerializedSale(string $serial, string $method, string $phone, string $sku = 'MFS-FIN')
    {
        $product = InventoryProduct::query()->where('sku', $sku)->firstOrFail();

        return $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Finance Customer', 'phone' => $phone],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: $method,
            actor: $this->actor,
        );
    }

    private function accountId(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }
}
