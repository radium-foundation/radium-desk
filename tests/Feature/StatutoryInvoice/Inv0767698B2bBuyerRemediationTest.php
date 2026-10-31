<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\AuditLog;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\StatutoryInvoiceRemediationRecord;
use App\Models\User;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\Inv0767698B2bBuyerRemediation;
use Database\Seeders\FinanceMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Inv0767698B2bBuyerRemediationTest extends TestCase
{
    use RefreshDatabase;

    private Inv0767698B2bBuyerRemediation $remediation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        $this->remediation = app(Inv0767698B2bBuyerRemediation::class);
    }

    public function test_refuses_invoice_id_other_than_16507(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->remediation->plan(16508, Inv0767698B2bBuyerRemediation::EXPECTED_INVOICE_NUMBER);
    }

    public function test_refuses_wrong_invoice_number(): void
    {
        $this->seedTargetInvoice();
        $this->expectException(\RuntimeException::class);
        $this->remediation->plan(Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID, 'INV-WRONG');
    }

    public function test_refuses_when_irn_exists(): void
    {
        $invoice = $this->seedTargetInvoice();
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'abc123',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->remediation->plan($invoice->id, $invoice->invoice_number);
    }

    public function test_dry_run_shows_submittable_payload_without_mutation(): void
    {
        $invoice = $this->seedTargetInvoice();
        $beforeGstin = $invoice->fresh()->buyer_gstin;

        $report = $this->remediation->plan($invoice->id, $invoice->invoice_number);

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['already_applied']);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_GSTIN, $report['after']['buyer_gstin']);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_LEGAL_NAME, $report['after']['buyer_legal_name']);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_TRADE_NAME, $report['after']['buyer_trade_name']);
        $this->assertTrue($report['financial_identity_unchanged']);
        $this->assertSame([], $report['einvoice_payload']['gaps']);
        $this->assertTrue($report['einvoice_payload']['is_submittable']);
        $this->assertSame('07', $report['einvoice_payload']['buyer_state_code']);
        $this->assertSame('07', $report['einvoice_payload']['buyer_pos_code']);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_LEGAL_NAME, $report['einvoice_payload']['buyer_legal_name']);
        $this->assertSame('B2B', $report['einvoice_payload']['supply_type']);
        $this->assertNull($beforeGstin);
        $this->assertNull($invoice->fresh()->buyer_gstin);
        $this->assertSame(0, StatutoryInvoiceRemediationRecord::query()->count());
    }

    public function test_execute_applies_once_and_is_idempotent(): void
    {
        $invoice = $this->seedTargetInvoice();
        $sale = InventorySale::query()->find(Inv0767698B2bBuyerRemediation::EXPECTED_INVENTORY_SALE_ID);

        $first = $this->remediation->execute($invoice->id, $invoice->invoice_number, 'test-actor');
        $this->assertTrue($first['applied']);

        $fresh = StatutoryInvoice::query()->find($invoice->id);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_GSTIN, $fresh?->buyer_gstin);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_LEGAL_NAME, $fresh?->buyer_name);
        $this->assertSame('99800.00', (string) $fresh?->taxable_value);
        $this->assertSame('117764.00', (string) $fresh?->invoice_value);

        $saleFresh = $sale?->fresh();
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_GSTIN, $saleFresh?->buyer_gstin);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_LEGAL_NAME, $saleFresh?->buyer_name);

        $this->assertSame(1, StatutoryInvoiceRemediationRecord::query()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'statutory_invoice.b2b_buyer_remediation')
            ->where('auditable_id', $invoice->id)
            ->count());

        $second = $this->remediation->execute($invoice->id, $invoice->invoice_number, 'test-actor');
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, StatutoryInvoiceRemediationRecord::query()->count());
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'statutory_invoice.b2b_buyer_remediation')
            ->count());
    }

    public function test_post_apply_payload_maps_buyer_fields(): void
    {
        $invoice = $this->seedTargetInvoice();
        $this->remediation->execute($invoice->id, $invoice->invoice_number, 'test-actor');

        $payload = app(EInvoiceIrnPayloadMapper::class)->map(
            StatutoryInvoice::query()->with('items')->findOrFail($invoice->id),
        );

        $this->assertTrue($payload->isSubmittable());
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_GSTIN, $payload->buyer['gstin']);
        $this->assertSame(Inv0767698B2bBuyerRemediation::BUYER_LEGAL_NAME, $payload->buyer['legal_name']);
        $this->assertSame('07', $payload->buyer['state_code']);
    }

    public function test_other_issued_invoices_remain_immutable_via_eloquent(): void
    {
        $other = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-OTHER-1',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'source_id' => '999',
            'idempotency_key' => 'statutory:other:1',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Other Buyer',
            'buyer_gstin' => null,
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'invoice_value' => '118.00',
            'issued_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $other->update(['buyer_gstin' => '07AAAAA0000A1Z5']);
    }

    public function test_command_dry_run_by_default(): void
    {
        $this->seedTargetInvoice();

        $this->artisan('desk:remediate-inv-0767698-b2b-buyer', [
            'invoice_id' => (string) Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
        ])->assertSuccessful();

        $this->assertNull(
            StatutoryInvoice::query()->find(Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID)?->buyer_gstin,
        );
    }

    private function seedTargetInvoice(): StatutoryInvoice
    {
        $structured = [
            'line1' => 'Shop no 6, Agarwal Complex Plot no 1, LSC, Surajmal Vihar, East Delhi, Delhi, 110092',
            'city' => 'Delhi',
            'state' => 'Delhi',
            'pincode' => '110092',
        ];

        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $issuer = User::factory()->create();
        InventoryCustomer::query()->insert([
            'id' => 803,
            'name' => Inv0767698B2bBuyerRemediation::EXPECTED_PRE_BUYER_NAME,
            'phone' => '9560848503',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StatutoryInvoice::query()->insert([
            'id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
            'invoice_number' => Inv0767698B2bBuyerRemediation::EXPECTED_INVOICE_NUMBER,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice->value,
            'status' => StatutoryInvoiceStatus::Issued->value,
            'channel' => StatutoryInvoiceChannel::DeskPos->value,
            'source_type' => StatutoryInvoiceSourceType::InventorySale->value,
            'source_id' => '127',
            'source_order_id' => Inv0767698B2bBuyerRemediation::EXPECTED_SOURCE_ORDER_ID,
            'idempotency_key' => 'statutory:desk_pos:inventory_sale:127',
            'inventory_sale_id' => null,
            'branch_id' => $branch->id,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => Inv0767698B2bBuyerRemediation::EXPECTED_PRE_BUYER_NAME,
            'buyer_phone' => '9560848503',
            'buyer_gstin' => null,
            'billing_address' => $structured['line1'],
            'billing_address_structured' => json_encode($structured),
            'place_of_supply_state' => 'Delhi',
            'place_of_supply_state_code' => '07',
            'place_of_supply_source' => 'goods_delivery_destination',
            'taxable_value' => '99800.00',
            'shipping_amount' => '0.00',
            'discount' => '0.00',
            'tax_total' => '17964.00',
            'cgst' => '8982.00',
            'sgst' => '8982.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '117764.00',
            'issued_by' => $issuer->id,
            'issued_at' => '2026-10-31 17:12:11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InventorySale::query()->insert([
            'id' => Inv0767698B2bBuyerRemediation::EXPECTED_INVENTORY_SALE_ID,
            'sale_no' => Inv0767698B2bBuyerRemediation::EXPECTED_SOURCE_ORDER_ID,
            'idempotency_key' => 'pos-sale-6827',
            'invoice_number' => 'INV-DELHI-RETAIL-2026-00122',
            'statutory_invoice_id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
            'branch_id' => $branch->id,
            'customer_id' => 803,
            'buyer_name' => Inv0767698B2bBuyerRemediation::EXPECTED_PRE_BUYER_NAME,
            'buyer_gstin' => null,
            'billing_address' => $structured['line1'],
            'billing_address_structured' => json_encode($structured),
            'place_of_supply_state' => 'Delhi',
            'status' => 'completed',
            'subtotal' => '99800.00',
            'discount' => '0.00',
            'tax' => '17964.00',
            'shipping_amount' => '0.00',
            'total' => '117764.00',
            'payment_reference' => '__PAYMENT_PENDING__',
            'finance_handoff_status' => 'posted',
            'finance_journal_id' => null,
            'created_by' => $issuer->id,
            'completed_at' => '2026-10-31 17:12:11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        StatutoryInvoice::query()
            ->where('id', Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID)
            ->update(['inventory_sale_id' => Inv0767698B2bBuyerRemediation::EXPECTED_INVENTORY_SALE_ID]);

        StatutoryInvoiceItem::query()->insert([
            [
                'invoice_id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
                'line_no' => 1,
                'sku' => 'RBMFS110L1',
                'description' => 'RBMFS110L1 — Mantra MFS 110 L1',
                'hsn_sac' => '84716050',
                'uqc' => 'PCS',
                'qty' => 50,
                'unit_price' => '1900.00',
                'discount' => '0.00',
                'gst_percentage' => '18.00',
                'taxable_value' => '95000.00',
                'tax_total' => '17100.00',
                'cgst' => '8550.00',
                'sgst' => '8550.00',
                'igst' => '0.00',
                'line_total' => '112100.00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
                'line_no' => 2,
                'sku' => 'RBMIS100IR',
                'description' => 'RBMIS100IR — Mantra MIS100 V2',
                'hsn_sac' => '84716050',
                'uqc' => 'PCS',
                'qty' => 2,
                'unit_price' => '2400.00',
                'discount' => '0.00',
                'gst_percentage' => '18.00',
                'taxable_value' => '4800.00',
                'tax_total' => '864.00',
                'cgst' => '432.00',
                'sgst' => '432.00',
                'igst' => '0.00',
                'line_total' => '5664.00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        EInvoiceRecord::query()->insert([
            'id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
            'invoice_id' => Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID,
            'provider' => 'whitebooks',
            'status' => EInvoiceRecordStatus::Skipped->value,
            'response_payload' => json_encode(['skip_reason' => 'b2c_not_eligible']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StatutoryInvoice::query()->with('items')->findOrFail(Inv0767698B2bBuyerRemediation::TARGET_INVOICE_ID);
    }
}
