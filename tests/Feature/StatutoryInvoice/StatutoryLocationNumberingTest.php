<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InvoiceSequence;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Services\StatutoryInvoice\StatutoryInvoiceScope;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryLocationNumberingTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);

        config([
            'statutory_invoices.location_series.enabled' => true,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.gstin_scope' => '07AAICP1128M1Z9',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_delhi_starts_at_inv_07671_and_increments_independently(): void
    {
        $first = $this->invoices->mint($this->request('delhi-a', StatutoryLocationSeries::DELHI), $this->actor);
        $second = $this->invoices->mint($this->request('delhi-b', StatutoryLocationSeries::DELHI), $this->actor);

        $this->assertSame('INV-07671', $first->invoice_number);
        $this->assertSame('INV-07672', $second->invoice_number);
        $this->assertSame(1, $first->allocation?->seq_int);
        $this->assertSame(2, $second->allocation?->seq_int);
    }

    public function test_mumbai_starts_at_inv_27671_and_increments_independently(): void
    {
        $first = $this->invoices->mint($this->request('mumbai-a', StatutoryLocationSeries::MUMBAI), $this->actor);
        $second = $this->invoices->mint($this->request('mumbai-b', StatutoryLocationSeries::MUMBAI), $this->actor);

        $this->assertSame('INV-27671', $first->invoice_number);
        $this->assertSame('INV-27672', $second->invoice_number);
    }

    public function test_delhi_and_mumbai_sequences_do_not_collide(): void
    {
        $delhi = $this->invoices->mint($this->request('d1', StatutoryLocationSeries::DELHI), $this->actor);
        $mumbai = $this->invoices->mint($this->request('m1', StatutoryLocationSeries::MUMBAI), $this->actor);
        $delhiTwo = $this->invoices->mint($this->request('d2', StatutoryLocationSeries::DELHI), $this->actor);
        $mumbaiTwo = $this->invoices->mint($this->request('m2', StatutoryLocationSeries::MUMBAI), $this->actor);

        $this->assertSame(['INV-07671', 'INV-27671', 'INV-07672', 'INV-27672'], [
            $delhi->invoice_number,
            $mumbai->invoice_number,
            $delhiTwo->invoice_number,
            $mumbaiTwo->invoice_number,
        ]);
        $this->assertSame(4, StatutoryInvoice::query()->count());
        $this->assertSame(2, InvoiceSequence::query()->count());
        $this->assertNotSame($delhi->invoice_number, $mumbai->invoice_number);
    }

    public function test_reprint_and_repeat_mint_keep_the_same_number(): void
    {
        $first = $this->invoices->mint($this->request('same', StatutoryLocationSeries::DELHI), $this->actor);
        $again = $this->invoices->mint($this->request('same', StatutoryLocationSeries::DELHI), $this->actor);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('INV-07671', $again->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, (int) InvoiceSequence::query()->where('series_code', 'INV-0767')->value('current_value'));
    }

    public function test_location_numbers_are_not_pos_internal_receipts(): void
    {
        $invoice = $this->invoices->mint($this->request('pos-shape', StatutoryLocationSeries::DELHI), $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertDoesNotMatchRegularExpression('/^INV-[A-Z0-9]+-\d{4}-\d{5}$/', $invoice->invoice_number);
    }

    public function test_unmapped_branch_codes_fail_closed(): void
    {
        $this->expectException(ValidationException::class);

        app(StatutoryLocationSeries::class)->requireFromBranchCode('HQ');
    }

    public function test_documented_branch_codes_map_to_delhi_and_mumbai(): void
    {
        $series = app(StatutoryLocationSeries::class);

        $this->assertSame(StatutoryLocationSeries::DELHI, $series->resolveFromBranchCode('DELHI-RETAIL'));
        $this->assertSame(StatutoryLocationSeries::MUMBAI, $series->resolveFromBranchCode('mumbai'));
        $this->assertNull($series->resolveFromBranchCode('DELHI-WH'));
        $this->assertNull($series->resolveFromBranchCode('BIHAR'));
        $this->assertNull($series->resolveFromBranchCode(null));
    }

    public function test_pos_receipt_sequence_on_a_delhi_branch_is_unchanged(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
            'invoice_sequence' => 0,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-NUM',
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, ['NUM-SERIAL-1'], $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000098'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['NUM-SERIAL-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );
        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertMatchesRegularExpression('/^INV-DELHI-RETAIL-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertNotSame($sale->invoice_number, $invoice->invoice_number);
        $this->assertSame($sale->invoice_number, $sale->fresh()->invoice_number);
        $this->assertSame(1, $branch->fresh()->invoice_sequence);
    }

    public function test_effective_scope_remains_first_september_2026(): void
    {
        $this->assertSame('2026-09-01 00:00:00', StatutoryInvoiceScope::STARTS_AT);
        $this->assertSame('2026-09-01 00:00:00', config('statutory_invoices.invoice_scope_starts_at'));
        $this->assertFalse(StatutoryInvoiceScope::contains(Carbon::parse('2026-08-31 23:59:59')));
        $this->assertTrue(StatutoryInvoiceScope::contains(Carbon::parse('2026-09-01 00:00:00')));
    }

    public function test_issued_statutory_number_cannot_be_renumbered(): void
    {
        $invoice = $this->invoices->mint($this->request('keep-number', StatutoryLocationSeries::DELHI), $this->actor);

        try {
            $invoice->update(['invoice_number' => 'INV6796998']);
            $this->fail('Expected immutability guard.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $next = $this->invoices->mint($this->request('delhi-next', StatutoryLocationSeries::DELHI), $this->actor);

        $this->assertSame('INV-07671', $invoice->fresh()->invoice_number);
        $this->assertSame('INV-07672', $next->invoice_number);
        $this->assertSame('INV-07671', $this->invoices->mint($this->request('keep-number', StatutoryLocationSeries::DELHI), $this->actor)->invoice_number);
    }

    public function test_delhi_serials_five_999_and_1000_use_unpadded_running_serial(): void
    {
        $series = app(StatutoryLocationSeries::class);
        $year = StatutoryFinancialYear::fromToken('2026-2027');
        InvoiceSequence::query()->create([
            'sequence_key' => $series->sequenceKey(StatutoryLocationSeries::DELHI, $year),
            'series_code' => $series->prefix(StatutoryLocationSeries::DELHI, $year),
            'document_type' => 'tax_invoice',
            'gstin_scope' => 'location:delhi',
            'financial_year' => $year->token(),
            'current_value' => 4,
        ]);

        $fifth = $this->invoices->mint($this->request('serial-5', StatutoryLocationSeries::DELHI), $this->actor);
        $this->assertSame('INV-07675', $fifth->invoice_number);

        InvoiceSequence::query()
            ->where('sequence_key', $series->sequenceKey(StatutoryLocationSeries::DELHI, $year))
            ->update(['current_value' => 998]);

        $serial999 = $this->invoices->mint($this->request('serial-999', StatutoryLocationSeries::DELHI), $this->actor);
        $serial1000 = $this->invoices->mint($this->request('serial-1000', StatutoryLocationSeries::DELHI), $this->actor);

        $this->assertSame('INV-0767999', $serial999->invoice_number);
        $this->assertSame('INV-07671000', $serial1000->invoice_number);
    }

    public function test_financial_years_are_isolated_and_reset_serial_to_one(): void
    {
        $fy26 = $this->invoices->mint($this->request('fy26-a', StatutoryLocationSeries::DELHI, '2026-2027'), $this->actor);
        $fy27Delhi = $this->invoices->mint($this->request('fy27-d', StatutoryLocationSeries::DELHI, '2027-2028'), $this->actor);
        $fy27Mumbai = $this->invoices->mint($this->request('fy27-m', StatutoryLocationSeries::MUMBAI, '2027-2028'), $this->actor);
        $fy26b = $this->invoices->mint($this->request('fy26-b', StatutoryLocationSeries::DELHI, '2026-2027'), $this->actor);

        $this->assertSame('INV-07671', $fy26->invoice_number);
        $this->assertSame('INV-07781', $fy27Delhi->invoice_number);
        $this->assertSame('INV-27781', $fy27Mumbai->invoice_number);
        $this->assertSame('INV-07672', $fy26b->invoice_number);
        $this->assertSame(3, InvoiceSequence::query()->count());
    }

    public function test_formula_examples_match_owner_fy_codes(): void
    {
        $series = app(StatutoryLocationSeries::class);
        $fy26 = StatutoryFinancialYear::fromToken('2026-2027');
        $fy27 = StatutoryFinancialYear::fromToken('2027-2028');

        $this->assertSame('67', $fy26->code());
        $this->assertSame('78', $fy27->code());
        $this->assertSame('67', StatutoryFinancialYear::containing(Carbon::parse('2026-09-01'))->code());
        $this->assertSame('67', StatutoryFinancialYear::containing(Carbon::parse('2027-03-31'))->code());
        $this->assertSame('78', StatutoryFinancialYear::containing(Carbon::parse('2027-04-01'))->code());
        $this->assertSame('INV-07671', $series->formatNumber(StatutoryLocationSeries::DELHI, $fy26, 1));
        $this->assertSame('INV-27672', $series->formatNumber(StatutoryLocationSeries::MUMBAI, $fy26, 2));
        $this->assertSame('INV-07781', $series->formatNumber(StatutoryLocationSeries::DELHI, $fy27, 1));
        $this->assertSame('INV-27781', $series->formatNumber(StatutoryLocationSeries::MUMBAI, $fy27, 1));
        $this->assertSame('INV-0767999', $series->formatNumber(StatutoryLocationSeries::DELHI, $fy26, 999));
        $this->assertSame('INV-07671000', $series->formatNumber(StatutoryLocationSeries::DELHI, $fy26, 1000));
    }

    private function request(string $sourceId, string $location, string $fy = '2026-2027'): StatutoryInvoiceMintRequest
    {
        return new StatutoryInvoiceMintRequest(
            channel: StatutoryInvoiceChannel::DeskPos,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: $sourceId,
            lines: [new StatutoryInvoiceLineDraft(
                description: 'Line',
                qty: 1,
                unitPrice: 10,
                gstPercentage: 18,
                taxTotal: 1.8,
                lineTotal: 11.8,
                taxableValue: 10,
                hsnSac: '8471',
            )],
            numberingLocation: $location,
            financialYearToken: $fy,
        );
    }
}
