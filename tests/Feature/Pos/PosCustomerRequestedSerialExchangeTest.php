<?php

namespace Tests\Feature\Pos;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\PosCustomerRequestedSerialExchangeStatus;
use App\Enums\PosCustomerRequestedSerialExchangeStatutoryTreatment;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\PosCustomerRequestedSerialExchange;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Pos\PosCustomerRequestedSerialExchangeService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosCustomerRequestedSerialExchangeTest extends TestCase
{
    use RefreshDatabase;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi retail',
            'is_active' => true,
        ]);
    }

    public function test_pos_6754_shaped_exchange_with_statutory_gate_and_audit_trail(): void
    {
        [$sale, $originalNo, $replacementNo, $actor, $invoice] = $this->pos6754Fixture();
        $journalIdBefore = $sale->finance_journal_id;
        $invoiceBefore = $invoice->fresh()->toArray();
        $totalBefore = (string) $sale->total;
        $service = app(PosCustomerRequestedSerialExchangeService::class);

        $base = [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'pos-6754-exchange',
            'exchange_reason' => 'Customer-requested serial exchange (POS-6754)',
        ];

        try {
            $service->execute($actor, [
                ...$base,
                'physical_receipt_attestation_reason' => 'Owner confirms unit received and resaleable.',
            ]);
            $this->fail('Expected statutory block.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('statutory', $e->errors());
        }

        $service->recordStatutoryApproval($actor, [
            ...$base,
            'statutory_treatment_decision' => PosCustomerRequestedSerialExchangeStatutoryTreatment::SerialOnlyNoInvoiceChange->value,
            'statutory_evidence_reference' => 'CA memo POS-6754 serial-only 2026-11-07',
            'statutory_notes' => 'Preserve INV; serial numbers only.',
        ]);

        $exchange = $service->execute($actor, [
            ...$base,
            'physical_receipt_attestation_reason' => 'Owner confirms original unit physically received and resaleable (POS-6754).',
        ]);

        $this->assertSame(PosCustomerRequestedSerialExchangeStatus::Executed, $exchange->status);
        $this->assertNotNull($exchange->statutory_approved_at);
        $this->assertSame($actor->id, $exchange->statutory_approved_by);
        $this->assertNotNull($exchange->physical_receipt_attested_at);

        $sale->refresh();
        $this->assertSame($totalBefore, (string) $sale->total);
        $this->assertSame(4, InventorySaleSerial::query()->where('sale_id', $sale->id)->count());
        $this->assertSame($journalIdBefore, $sale->finance_journal_id);
        $this->assertSame($invoiceBefore, $invoice->fresh()->toArray());

        $original = InventorySerial::query()->where('serial_number', $originalNo)->firstOrFail();
        $replacement = InventorySerial::query()->where('serial_number', $replacementNo)->firstOrFail();
        $this->assertSame(InventorySerialStatus::Available, $original->status);
        $this->assertSame(InventorySerialStatus::Sold, $replacement->status);
        $this->assertTrue(
            InventorySaleSerial::query()
                ->where('sale_id', $sale->id)
                ->where('serial_id', $replacement->id)
                ->exists()
        );

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('type', InventoryMovementType::PosCustomerSerialExchangeRestore)
                ->where('serial_id', $original->id)
                ->count()
        );
        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('type', InventoryMovementType::PosCustomerSerialExchangeAssign)
                ->where('serial_id', $replacement->id)
                ->count()
        );
    }

    public function test_idempotency_key_does_not_duplicate_movements(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $service = app(PosCustomerRequestedSerialExchangeService::class);
        $base = [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'idem-6754',
            'exchange_reason' => 'Exchange',
        ];

        $first = $service->execute($actor, [
            ...$base,
            'physical_receipt_attestation_reason' => 'Received and resaleable.',
        ]);
        $second = $service->execute($actor, [
            ...$base,
            'physical_receipt_attestation_reason' => 'Received and resaleable.',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosCustomerRequestedSerialExchange::query()->count());
        $this->assertSame(
            1,
            InventoryMovement::query()->where('type', InventoryMovementType::PosCustomerSerialExchangeRestore)->count()
        );
        $this->assertSame(
            1,
            InventoryMovement::query()->where('type', InventoryMovementType::PosCustomerSerialExchangeAssign)->count()
        );
    }

    public function test_missing_physical_attestation_fails(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $service = app(PosCustomerRequestedSerialExchangeService::class);

        $this->expectException(ValidationException::class);
        $service->execute($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'no-attest',
            'exchange_reason' => 'Exchange',
            'physical_receipt_attestation_reason' => '   ',
        ]);
    }

    public function test_failed_exchange_leaves_no_audit_or_exchange_movements(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $movementsBefore = InventoryMovement::query()->count();
        $exchangesBefore = PosCustomerRequestedSerialExchange::query()->count();

        $service = app(PosCustomerRequestedSerialExchangeService::class);
        try {
            $service->execute($actor, [
                'inventory_sale_id' => $sale->id,
                'original_serial_number' => $originalNo,
                'replacement_serial_number' => 'DOES-NOT-EXIST',
                'idempotency_key' => 'rollback-check',
                'exchange_reason' => 'Exchange',
                'physical_receipt_attestation_reason' => 'Received.',
            ]);
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($exchangesBefore, PosCustomerRequestedSerialExchange::query()->count());
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $original = InventorySerial::query()->where('serial_number', $originalNo)->firstOrFail();
        $this->assertSame(InventorySerialStatus::Sold, $original->status);
    }

    public function test_wrong_product_replacement_fails(): void
    {
        [$sale, $originalNo, , $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $other = InventoryProduct::query()->create([
            'sku' => 'OTHER-6754',
            'name' => 'Other',
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($other, $this->branch, ['WRONG-SN'], $actor);

        $service = app(PosCustomerRequestedSerialExchangeService::class);
        $this->expectException(ValidationException::class);
        $service->execute($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => 'WRONG-SN',
            'idempotency_key' => 'wrong-prod',
            'exchange_reason' => 'Exchange',
            'physical_receipt_attestation_reason' => 'Received.',
        ]);
    }

    public function test_unavailable_replacement_fails(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $replacement = InventorySerial::query()->where('serial_number', $replacementNo)->firstOrFail();
        $replacement->update(['status' => InventorySerialStatus::Damaged]);

        $service = app(PosCustomerRequestedSerialExchangeService::class);
        $this->expectException(ValidationException::class);
        $service->execute($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'bad-repl',
            'exchange_reason' => 'Exchange',
            'physical_receipt_attestation_reason' => 'Received.',
        ]);
    }

    public function test_statutory_decision_requiring_invoice_change_blocks(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture();
        $service = app(PosCustomerRequestedSerialExchangeService::class);

        $this->expectException(ValidationException::class);
        $service->recordStatutoryApproval($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'block-cn',
            'exchange_reason' => 'Exchange',
            'statutory_treatment_decision' => PosCustomerRequestedSerialExchangeStatutoryTreatment::RequiresCreditNote->value,
            'statutory_evidence_reference' => 'CN required',
        ]);
    }

    public function test_after_execution_original_no_longer_on_sale_blocks_replay_with_new_key(): void
    {
        [$sale, $originalNo, $replacementNo, $actor] = $this->pos6754Fixture(issuedInvoice: false);
        $anotherNo = 'LN2505155999';
        app(InventoryStockService::class)->stockInSerialized(
            InventoryProduct::query()->where('sku', 'RBPB1000L1')->firstOrFail(),
            $this->branch,
            [$anotherNo],
            $actor,
        );

        $service = app(PosCustomerRequestedSerialExchangeService::class);
        $service->execute($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'first-run',
            'exchange_reason' => 'Exchange',
            'physical_receipt_attestation_reason' => 'Received.',
        ]);

        $this->expectException(ValidationException::class);
        $service->execute($actor, [
            'inventory_sale_id' => $sale->id,
            'original_serial_number' => $originalNo,
            'replacement_serial_number' => $anotherNo,
            'idempotency_key' => 'second-run',
            'exchange_reason' => 'Exchange',
            'physical_receipt_attestation_reason' => 'Received.',
        ]);
    }

    /**
     * @return array{0: InventorySale, 1: string, 2: string, 3: User, 4?: StatutoryInvoice}
     */
    private function pos6754Fixture(bool $issuedInvoice = true): array
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $actor->id,
            'branch_id' => $this->branch->id,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBPB1000L1',
            'name' => 'Power bank',
            'gst_percentage' => 18,
            'unit_price' => 3894,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serials = [];
        for ($i = 0; $i < 4; $i++) {
            $serials[] = 'LN25'.random_int(100000, 999999);
        }
        $originalNo = $serials[3];
        $replacementNo = 'LN2505155964';

        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($product, $this->branch, $serials, $actor);
        $stock->stockInSerialized($product, $this->branch, [$replacementNo], $actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer', 'phone' => '99999006754'],
            lines: [['product_id' => $product->id, 'qty' => 4, 'serials' => $serials]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $invoice = null;
        if ($issuedInvoice) {
            $invoice = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-0767321-FIXTURE',
                'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
                'status' => StatutoryInvoiceStatus::Issued,
                'channel' => StatutoryInvoiceChannel::DeskPos,
                'inventory_sale_id' => $sale->id,
                'source_type' => StatutoryInvoiceSourceType::InventorySale,
                'source_id' => (string) $sale->id,
                'idempotency_key' => 'inv-pos6754-'.random_int(1, 99999),
                'seller_gstin' => '07AAICP1128M1Z9',
                'seller_name' => 'Seller',
                'buyer_name' => 'Buyer',
                'billing_address' => 'Addr',
                'place_of_supply_state' => 'Delhi',
                'taxable_value' => '13188.14',
                'tax_total' => '2377.86',
                'invoice_value' => '15576.00',
                'issued_at' => now(),
            ]);
            $sale->update(['statutory_invoice_id' => $invoice->id]);
        }

        $sale->refresh();

        if ($invoice !== null) {
            return [$sale, $originalNo, $replacementNo, $actor, $invoice];
        }

        return [$sale, $originalNo, $replacementNo, $actor];
    }
}
