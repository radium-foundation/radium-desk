<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\StatutoryBillingStructuredResolver;
use Database\Seeders\FinanceMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryBillingStructuredResolverTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryBillingStructuredResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceMasterDataSeeder::class);
        $this->resolver = app(StatutoryBillingStructuredResolver::class);
    }

    public function test_commerce_order_structured_is_used_when_invoice_structured_is_null(): void
    {
        $invoice = $this->commerceOriginal([
            'billing_address_structured' => null,
        ]);
        $this->createCommerceOrder($invoice, $this->completeStructured());

        $resolved = $this->resolver->resolveForInvoice($invoice);

        $this->assertSame($this->completeStructured(), $resolved);
    }

    public function test_complete_invoice_structured_is_preferred_over_commerce_source(): void
    {
        $invoiceStructured = [
            'line1' => 'Invoice Lane',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ];
        $invoice = $this->commerceOriginal([
            'billing_address_structured' => $invoiceStructured,
        ]);
        $this->createCommerceOrder($invoice, [
            'line1' => 'Commerce Lane',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
        ]);

        $this->assertSame($invoiceStructured, $this->resolver->resolveForInvoice($invoice));
    }

    public function test_incomplete_invoice_structured_falls_back_to_complete_commerce_source(): void
    {
        $invoice = $this->commerceOriginal([
            'billing_address_structured' => [
                'line1' => 'Partial only',
                'city' => 'Jharsuguda',
            ],
        ]);
        $commerceStructured = [
            'line1' => 'Ekatali,Siria Bagicha',
            'city' => 'Jharsuguda',
            'state' => 'Odisha',
            'pincode' => '768201',
        ];
        $this->createCommerceOrder($invoice, $commerceStructured);

        $this->assertSame($commerceStructured, $this->resolver->resolveForInvoice($invoice));
    }

    public function test_pos_sale_structured_is_used_for_inventory_sale_source(): void
    {
        $branch = InventoryBranch::query()->first() ?? InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-RESOLVER-1',
            'branch_id' => $branch->id,
            'status' => 'completed',
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'billing_address_structured' => $this->completeStructured(),
            'completed_at' => now(),
        ]);
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-POS-RESOLVER-1',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale->value,
            'source_id' => (string) $sale->id,
            'inventory_sale_id' => $sale->id,
            'idempotency_key' => 'statutory:pos-resolver:1',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now(),
        ]);

        $this->assertSame($this->completeStructured(), $this->resolver->resolveForInvoice($invoice->fresh(['inventorySale'])));
    }

    public function test_missing_authoritative_structured_returns_null_and_field_labels(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-NO-STRUCTURED',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'no-structured',
            'idempotency_key' => 'statutory:no-structured',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now(),
        ]);

        $this->assertNull($this->resolver->resolveForInvoice($invoice));
        $this->assertSame(['billing_address_structured'], $this->resolver->missingIrnFieldLabels($invoice));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function commerceOriginal(array $overrides = []): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create(array_merge([
            'invoice_number' => 'INV-COMMERCE-RESOLVER',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE-RESOLVER-1',
            'idempotency_key' => 'statutory:commerce-resolver:1',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'SANJIVANI HOSPITAL',
            'buyer_gstin' => '21AAOPA5417F1Z8',
            'billing_address' => 'Ekatali,Siria Bagicha, Jharsuguda, Odisha, 768201',
            'place_of_supply_state' => 'Odisha',
            'taxable_value' => 2880.51,
            'tax_total' => 518.49,
            'igst' => 518.49,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 3399.00,
            'issued_at' => now()->subDays(8),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function createCommerceOrder(StatutoryInvoice $invoice, array $structured): CommerceOrder
    {
        return CommerceOrder::query()->create([
            'order_no' => 'CO-'.$invoice->source_id,
            'channel' => $invoice->channel,
            'source_type' => 'commerce_order',
            'source_id' => $invoice->source_id,
            'source_order_id' => $invoice->source_id,
            'idempotency_key' => 'statutory:commerce-order:'.$invoice->id,
            'payload_hash' => hash('sha256', 'commerce-order-'.$invoice->id),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => $invoice->buyer_name,
            'buyer_gstin' => $invoice->buyer_gstin,
            'billing_address' => $invoice->billing_address,
            'billing_address_structured' => $structured,
            'place_of_supply_state' => $invoice->place_of_supply_state,
            'taxable_value' => $invoice->taxable_value,
            'tax_total' => $invoice->tax_total,
            'order_value' => $invoice->invoice_value,
            'statutory_invoice_id' => $invoice->id,
            'ordered_at' => now()->subDays(8),
            'received_at' => now()->subDays(8),
        ]);
    }

    /**
     * @return array{line1: string, city: string, state: string, pincode: string}
     */
    private function completeStructured(): array
    {
        return [
            'line1' => '1 Test Street',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ];
    }
}
