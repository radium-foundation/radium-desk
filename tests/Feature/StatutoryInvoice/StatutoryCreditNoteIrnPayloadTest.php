<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\StatutoryInvoiceCreditNoteService;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksNicPayloadFactory;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class StatutoryCreditNoteIrnPayloadTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const ORIGINAL_IRN = 'b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2';

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
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_credit_note_mapper_uses_crn_and_original_invoice_references(): void
    {
        [$original, $creditNote] = $this->pairedInvoices();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($creditNote->fresh(['items', 'originalStatutoryInvoice.eInvoiceRecord']));

        $this->assertSame('CRN', $payload->document['type']);
        $this->assertSame($creditNote->invoice_number, $payload->document['number']);
        $this->assertSame($creditNote->issued_at?->format('d/m/Y'), $payload->document['date']);
        $this->assertSame('07AAAAA0000A1Z5', $payload->buyer['gstin']);
        $this->assertSame('100.00', $payload->values['taxable_value']);
        $this->assertSame('9.00', $payload->values['cgst']);
        $this->assertSame('9.00', $payload->values['sgst']);
        $this->assertSame('0.00', $payload->values['igst']);
        $this->assertSame('118.00', $payload->values['invoice_value']);
        $this->assertSame($original->invoice_number, $payload->references['prec_doc']['number']);
        $this->assertSame($original->issued_at?->format('d/m/Y'), $payload->references['prec_doc']['date']);
        $this->assertSame(self::ORIGINAL_IRN, $payload->references['prec_doc']['irn']);
    }

    public function test_whitebooks_generate_body_includes_ref_dtls_for_credit_note(): void
    {
        [, $creditNote] = $this->pairedInvoices();
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($creditNote->fresh(['items', 'originalStatutoryInvoice.eInvoiceRecord']));
        $payload = $payload->withTestIrpFixtures(
            seller: ['pin' => '110019', 'location' => 'New Delhi'],
            buyer: ['pin' => '110001', 'location' => 'New Delhi'],
            items: $payload->items,
            removeGaps: $payload->gaps,
        );
        $body = (new WhitebooksNicPayloadFactory)->generateBody($payload);

        $this->assertIsArray($body);
        $this->assertSame('CRN', $body['DocDtls']['Typ'] ?? null);
        $this->assertArrayHasKey('RefDtls', $body);
        $precDoc = $body['RefDtls']['PrecDocDtls'][0] ?? null;
        $this->assertIsArray($precDoc);
        $this->assertSame('INV-CN-ORIG-1', $precDoc['InvNo'] ?? null);
        $this->assertSame('10/09/2026', $precDoc['InvDt'] ?? null);
        $this->assertSame(self::ORIGINAL_IRN, $precDoc['Irn'] ?? null);
        $this->assertSame('100.00', number_format((float) ($body['ValDtls']['AssVal'] ?? 0), 2, '.', ''));
        $this->assertSame('118.00', number_format((float) ($body['ValDtls']['TotInvVal'] ?? 0), 2, '.', ''));
    }

    public function test_credit_note_irn_eligibility_requires_linked_original_with_irn(): void
    {
        [, $creditNote] = $this->pairedInvoices();

        $decision = app(EInvoiceEligibility::class)->evaluate($creditNote->fresh(['originalStatutoryInvoice.eInvoiceRecord']));

        $this->assertTrue($decision->eligible);
        $this->assertSame('b2b_eligible', $decision->reason);
    }

    public function test_credit_note_issue_is_idempotent_and_original_invoice_stays_unchanged(): void
    {
        $original = $this->originalInvoiceWithIrn();
        $before = $original->only(['status', 'invoice_number', 'taxable_value', 'invoice_value', 'cancelled_at']);
        $service = app(StatutoryInvoiceCreditNoteService::class);
        $key = $service->idempotencyKeyFor($original);

        $first = $service->issueForCancellation($original, $this->admin, 'Adjustment', $key);
        $second = $service->issueForCancellation($original->fresh(), $this->admin, 'Adjustment retry', $key.'-dup');

        $this->assertFalse($first['idempotent'] ?? false);
        $this->assertTrue($second['idempotent'] ?? false);
        $this->assertSame($first['credit_note_id'], $second['credit_note_id']);
        $this->assertSame($before, $original->fresh()->only(['status', 'invoice_number', 'taxable_value', 'invoice_value', 'cancelled_at']));
        $this->assertSame(StatutoryInvoiceStatus::Issued, $original->fresh()->status);
    }

    private function originalInvoiceWithIrn(): StatutoryInvoice
    {
        $original = $this->makeSubmittableOriginalInvoice('INV-CN-ORIG-2');

        EInvoiceRecord::query()->create([
            'invoice_id' => $original->id,
            'provider' => 'fake',
            'irn' => self::ORIGINAL_IRN,
            'ack_no' => 'ACK-ORIG-2',
            'ack_date' => Carbon::parse('2026-09-10 10:05:00'),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        return $original->fresh(['items', 'eInvoiceRecord']) ?? $original;
    }

    /**
     * @return array{0: StatutoryInvoice, 1: StatutoryInvoice}
     */
    private function makeSubmittableOriginalInvoice(string $invoiceNumber): StatutoryInvoice
    {
        $original = $this->makeTaxInvoice([
            'invoice_number' => $invoiceNumber,
            'issued_at' => Carbon::parse('2026-09-10 10:00:00'),
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-'.$original->source_id,
            'channel' => $original->channel,
            'source_type' => 'commerce_order',
            'source_id' => $original->source_id,
            'source_order_id' => $original->source_id,
            'idempotency_key' => 'statutory:einvoice:commerce:'.$original->id,
            'payload_hash' => hash('sha256', 'einvoice-'.$original->id),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer Industries',
            'buyer_gstin' => $original->buyer_gstin,
            'billing_address' => '1 Test Street, Delhi',
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'statutory_invoice_id' => $original->id,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);

        return $original->fresh(['items']) ?? $original;
    }

    /**
     * @return array{0: StatutoryInvoice, 1: StatutoryInvoice}
     */
    private function pairedInvoices(): array
    {
        $original = $this->makeSubmittableOriginalInvoice('INV-CN-ORIG-1');

        EInvoiceRecord::query()->create([
            'invoice_id' => $original->id,
            'provider' => 'fake',
            'irn' => self::ORIGINAL_IRN,
            'ack_no' => 'ACK-ORIG-1',
            'ack_date' => Carbon::parse('2026-09-10 10:05:00'),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        $creditNote = StatutoryInvoice::query()->create([
            'invoice_number' => 'CN-2026-0001',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::CancellationAdjustment->value,
            'source_id' => (string) $original->id,
            'idempotency_key' => 'statutory-credit-note:test:1',
            'original_statutory_invoice_id' => $original->id,
            'seller_gstin' => $original->seller_gstin,
            'seller_name' => $original->seller_name,
            'buyer_name' => $original->buyer_name,
            'buyer_gstin' => $original->buyer_gstin,
            'billing_address' => $original->billing_address,
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => $original->place_of_supply_state,
            'taxable_value' => $original->taxable_value,
            'tax_total' => $original->tax_total,
            'cgst' => $original->cgst,
            'sgst' => $original->sgst,
            'igst' => $original->igst,
            'rounding' => $original->rounding,
            'invoice_value' => $original->invoice_value,
            'issued_at' => Carbon::parse('2026-09-12 10:00:00'),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $creditNote->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'description' => 'Mantra MFS 110',
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

        return [$original->fresh(['items', 'eInvoiceRecord']), $creditNote->fresh(['items'])];
    }
}
