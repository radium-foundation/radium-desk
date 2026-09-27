<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryInvoiceCreditNoteService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatutoryCreditNoteIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_database_unique_index_prevents_duplicate_credit_notes_for_same_original(): void
    {
        $original = $this->issueOriginalInvoice('CN-IDEM-1');
        $service = app(StatutoryInvoiceCreditNoteService::class);

        $first = $service->issueForCancellation(
            $original,
            $this->admin,
            'First credit note',
            $service->idempotencyKeyFor($original),
        );
        $this->assertFalse($first['idempotent'] ?? false);

        $this->expectException(UniqueConstraintViolationException::class);
        StatutoryInvoice::query()->create([
            'invoice_number' => 'CN-DUPLICATE-DB',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => $original->channel,
            'source_type' => StatutoryInvoiceSourceType::CancellationAdjustment->value,
            'source_id' => (string) $original->id,
            'idempotency_key' => 'statutory-credit-note:duplicate-db',
            'original_statutory_invoice_id' => $original->id,
            'seller_gstin' => $original->seller_gstin,
            'seller_name' => $original->seller_name,
            'buyer_name' => $original->buyer_name,
            'buyer_gstin' => $original->buyer_gstin,
            'billing_address' => $original->billing_address,
            'place_of_supply_state' => $original->place_of_supply_state,
            'taxable_value' => $original->taxable_value,
            'tax_total' => $original->tax_total,
            'cgst' => $original->cgst,
            'sgst' => $original->sgst,
            'igst' => $original->igst,
            'rounding' => $original->rounding,
            'invoice_value' => $original->invoice_value,
            'issued_at' => now(),
        ]);
    }

    public function test_service_returns_idempotent_result_when_credit_note_already_exists(): void
    {
        $original = $this->issueOriginalInvoice('CN-IDEM-2');
        $service = app(StatutoryInvoiceCreditNoteService::class);
        $key = $service->idempotencyKeyFor($original);

        $first = $service->issueForCancellation($original, $this->admin, 'First', $key);
        $second = $service->issueForCancellation($original->fresh(), $this->admin, 'Second', $key.'-retry');

        $this->assertFalse($first['idempotent'] ?? false);
        $this->assertTrue($second['idempotent'] ?? false);
        $this->assertSame($first['credit_note_id'], $second['credit_note_id']);
        $this->assertSame(1, StatutoryInvoice::query()
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->where('original_statutory_invoice_id', $original->id)
            ->count());
    }

    public function test_tax_invoices_may_share_null_original_statutory_invoice_id(): void
    {
        $first = $this->issueOriginalInvoice('CN-IDEM-3A');
        $second = $this->issueOriginalInvoice('CN-IDEM-3B');

        $this->assertNull($first->original_statutory_invoice_id);
        $this->assertNull($second->original_statutory_invoice_id);
    }

    public function test_migrations_create_credit_note_original_unique_index(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('statutory_invoices')"))
            ->pluck('name')
            ->all();

        $this->assertContains('statutory_invoices_credit_note_original_unique', $indexes);
    }

    private function issueOriginalInvoice(string $marker): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$marker,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'cn-idem-'.$marker,
            'idempotency_key' => 'statutory:cn-idem:'.$marker,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'discount' => 0.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(10),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'description' => 'Hardware Item',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 100.00,
            'discount' => 0.00,
            'gst_percentage' => 18.00,
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'line_total' => 118.00,
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }
}
