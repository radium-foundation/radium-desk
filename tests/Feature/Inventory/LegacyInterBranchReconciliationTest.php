<?php

namespace Tests\Feature\Inventory;

use App\Enums\FinanceJournalSourceType;
use App\Enums\InterBranchReconciliationMode;
use App\Enums\InterBranchTransactionStatus;
use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\LegacyInterBranchCandidateStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\FinanceJournal;
use App\Models\InterBranchReconciliationAudit;
use App\Models\InterBranchTransaction;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Finance\PosSaleJournalService;
use App\Services\Inventory\InterBranchTransferService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\LegacyInterBranchReconciliationService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyInterBranchReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private LegacyInterBranchReconciliationService $reconciliation;

    private InterBranchTransferService $interBranch;

    private InventoryStockService $stock;

    private PosSaleService $pos;

    private User $actor;

    private InventoryBranch $delhi;

    private InventoryBranch $mumbai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'inter_branch.legacy_reconciliation.require_irn' => true,
        ]);

        $this->reconciliation = app(LegacyInterBranchReconciliationService::class);
        $this->interBranch = app(InterBranchTransferService::class);
        $this->stock = app(InventoryStockService::class);
        $this->pos = app(PosSaleService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->delhi = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
        $this->mumbai = InventoryBranch::query()->create([
            'code' => 'MUMBAI',
            'name' => 'Mumbai',
            'gstin' => '27AAICP1128M1Z7',
            'is_active' => true,
        ]);
    }

    public function test_legacy_reconciliation_reuses_invoice_and_moves_serials_to_mumbai(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-001', 'LEG-002']);
        $originalJournalId = $this->postFinanceJournal($sale);

        $invoiceCountBefore = StatutoryInvoice::query()->count();
        $irn = $invoice->fresh()->einvoiceRecord?->irn;

        $result = $this->reconciliation->reconcile(
            actor: $this->actor,
            saleId: $sale->id,
            dryRun: false,
            reason: 'test legacy reconcile',
        );

        $this->assertFalse($result->dryRun);
        $this->assertNotNull($result->transaction);
        $this->assertSame(InterBranchTransactionStatus::Completed, $result->transaction->status);
        $this->assertSame(InterBranchReconciliationMode::LegacyPosInterBranch, $result->transaction->reconciliation_mode);
        $this->assertSame($sale->id, $result->transaction->legacy_inventory_sale_id);
        $this->assertSame($invoice->id, $result->transaction->statutory_invoice_id);
        $this->assertSame($invoiceCountBefore, StatutoryInvoice::query()->count());

        $invoice->refresh();
        $this->assertSame(StatutoryInvoiceSourceType::InventorySale->value, $invoice->source_type);
        $this->assertSame($sale->id, $invoice->inventory_sale_id);
        $this->assertSame($irn, $invoice->einvoiceRecord?->irn);
        $this->assertSame($originalJournalId, $sale->fresh()->finance_journal_id);
        $this->assertSame(InventoryFinanceHandoffStatus::Posted, $sale->fresh()->finance_handoff_status);
        $this->assertSame(1, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::PosSale)->count());

        foreach (['LEG-001', 'LEG-002'] as $number) {
            $serial = InventorySerial::query()->where('serial_number', $number)->firstOrFail();
            $this->assertSame($this->mumbai->id, $serial->branch_id);
            $this->assertSame(InventorySerialStatus::Available, $serial->status);
        }

        $this->assertTrue($result->transaction->isReconciled());
        $this->assertNotNull($result->audit);
        $this->assertSame(
            LegacyInterBranchReconciliationService::FINANCE_TREATMENT_ORIGINAL_JOURNAL_PRESERVED,
            $result->audit->finance_treatment,
        );
    }

    public function test_legacy_reconciliation_is_idempotent(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-IDEM-1']);
        $this->postFinanceJournal($sale);

        $first = $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
        $second = $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);

        $this->assertSame($first->transaction?->id, $second->transaction?->id);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame(1, InterBranchTransaction::query()->count());
        $this->assertSame(1, InterBranchReconciliationAudit::query()->count());
    }

    public function test_dry_run_does_not_mutate_data(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-DRY-1']);
        $this->postFinanceJournal($sale);

        $result = $this->reconciliation->reconcile(
            actor: $this->actor,
            saleId: $sale->id,
            dryRun: true,
        );

        $this->assertTrue($result->dryRun);
        $this->assertTrue($result->assessment->isReconcilable());
        $this->assertNull($result->transaction);
        $this->assertSame(0, InterBranchTransaction::query()->count());
        $this->assertSame(
            InventorySerialStatus::Sold,
            InventorySerial::query()->where('serial_number', 'LEG-DRY-1')->value('status'),
        );
    }

    public function test_missing_irn_blocks_reconciliation(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-NO-IRN'], attachIrn: false);

        $assessment = $this->reconciliation->assess($sale->fresh());
        $this->assertSame(LegacyInterBranchCandidateStatus::Blocked, $assessment->status);

        $this->expectException(ValidationException::class);
        $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);

        $invoice->refresh();
        $this->assertNull($invoice->einvoiceRecord?->irn);
    }

    public function test_cancelled_invoice_blocks_reconciliation(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-CAN-1']);
        $invoice->update(['status' => StatutoryInvoiceStatus::Cancelled]);

        $this->expectException(ValidationException::class);
        $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
    }

    public function test_wrong_buyer_gstin_blocks_reconciliation(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-WRONG-GSTIN']);
        $sale->update(['buyer_gstin' => '29AAICP1128M1Z1']);

        $this->expectException(ValidationException::class);
        $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
    }

    public function test_serial_already_at_destination_blocks_reconciliation(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-DEST-1']);
        InventorySerial::query()
            ->where('serial_number', 'LEG-DEST-1')
            ->update([
                'branch_id' => $this->mumbai->id,
                'status' => InventorySerialStatus::Sold,
            ]);

        $this->expectException(ValidationException::class);
        $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
    }

    public function test_normal_ibt_issue_still_rejects_sold_serial(): void
    {
        $product = $this->serializedProduct('SKU-NORMAL-IBT');
        [$sale] = $this->legacyPosSale(['NORMAL-1'], product: $product);
        unset($sale);

        $this->expectException(ValidationException::class);
        $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['NORMAL-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'normal-after-legacy-sale',
        );
    }

    public function test_legacy_reconciliation_does_not_generate_eway_or_second_invoice(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-EWAY-1']);

        $result = $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
        $transaction = $result->transaction;

        $this->assertNull($transaction?->eway_bill_reference);
        $this->assertSame('not_applicable', $transaction?->eway_bill_status->value);
        $this->assertSame($invoice->id, $transaction?->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->whereKey($invoice->id)->count());
    }

    public function test_discover_classifies_reconcilable_candidate(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-DISC-1']);

        $candidates = $this->reconciliation->discoverCandidates();
        $match = collect($candidates)->firstWhere('saleId', $sale->id);

        $this->assertNotNull($match);
        $this->assertSame(LegacyInterBranchCandidateStatus::Reconcilable, $match->status);
        $this->assertSame($invoice->id, $match->invoiceId);
        $this->assertSame(1, $match->serialCount);
    }

    public function test_discover_marks_already_reconciled_after_execution(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-DISC-2']);
        $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);

        $match = collect($this->reconciliation->discoverCandidates())->firstWhere('saleId', $sale->id);
        $this->assertNotNull($match);
        $this->assertSame(LegacyInterBranchCandidateStatus::AlreadyReconciled, $match->status);
    }

    public function test_concurrent_reconciliation_attempts_create_single_ibt(): void
    {
        [$sale] = $this->legacyPosSale(['LEG-CONC-1']);

        $results = [];
        DB::transaction(function () use ($sale, &$results): void {
            $results[] = $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
        });
        DB::transaction(function () use ($sale, &$results): void {
            $results[] = $this->reconciliation->reconcile(actor: $this->actor, saleId: $sale->id);
        });

        $this->assertSame($results[0]->transaction?->id, $results[1]->transaction?->id);
        $this->assertSame(1, InterBranchTransaction::query()->count());
    }

    public function test_reconcile_by_invoice_id(): void
    {
        [$sale, $invoice] = $this->legacyPosSale(['LEG-INV-1']);

        $result = $this->reconciliation->reconcile(
            actor: $this->actor,
            invoiceId: $invoice->id,
        );

        $this->assertSame($sale->id, $result->transaction?->legacy_inventory_sale_id);
    }

    /**
     * @param  list<string>  $serials
     * @return array{0: InventorySale, 1: StatutoryInvoice}
     */
    private function legacyPosSale(
        array $serials,
        ?InventoryProduct $product = null,
        bool $attachIrn = true,
        ?string $buyerGstin = null,
    ): array {
        $product ??= $this->serializedProduct('SKU-LEG-'.substr(md5(implode(',', $serials)), 0, 6));
        $this->stock->stockInSerialized($product, $this->delhi, $serials, $this->actor);

        $buyerGstin ??= $this->mumbai->gstin;

        $sale = $this->pos->completeSale(
            branch: $this->delhi,
            customer: [
                'name' => 'Phil Mumbai',
                'phone' => '900000'.random_int(1000, 9999),
                'gstin' => $buyerGstin,
            ],
            lines: [[
                'product_id' => $product->id,
                'qty' => count($serials),
                'serials' => $serials,
            ]],
            paymentMethod: 'Bank Transfer',
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => $buyerGstin,
                'place_of_supply_state' => 'Maharashtra',
                'billing_address' => 'Mumbai Branch',
                'billing_city' => 'Mumbai',
                'billing_state' => 'Maharashtra',
                'billing_pincode' => '400001',
            ],
        );

        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale->fresh(['lines.product', 'serials.serial']), $this->actor);
        $invoice->update([
            'buyer_gstin' => $buyerGstin,
            'seller_gstin' => $this->configuredSellerGstin('delhi'),
        ]);
        $sale->update(['statutory_invoice_id' => $invoice->id, 'buyer_gstin' => $buyerGstin]);

        if ($attachIrn) {
            EInvoiceRecord::query()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'provider' => 'test',
                    'irn' => 'IRN'.strtoupper(substr(md5((string) $invoice->id), 0, 20)),
                    'status' => 'submitted',
                ],
            );
        }

        return [$sale->fresh(['statutoryInvoice.einvoiceRecord', 'serials.serial']), $invoice->fresh(['einvoiceRecord'])];
    }

    private function postFinanceJournal(InventorySale $sale): int
    {
        $journal = app(PosSaleJournalService::class)->postForSale($sale->fresh(), $this->actor);

        return (int) $journal?->id;
    }

    private function serializedProduct(string $sku): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Legacy inter-branch product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
