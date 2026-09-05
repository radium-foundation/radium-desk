<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutorySupplyKind;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Services\StatutoryInvoice\StatutorySellerIdentity;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutorySellerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private StatutorySellerIdentity $seller;

    private StatutoryBillingIssuer $issuer;

    private User $actor;

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
        ]);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->seller = app(StatutorySellerIdentity::class);
        $this->issuer = app(StatutoryBillingIssuer::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_product_issuer_uses_location_gstin_not_customer_state(): void
    {
        $delhi = $this->seller->requireForLocation(
            $this->issuer->require(StatutorySupplyKind::Product, 'DELHI-RETAIL', '27AAAAA0000A1Z5', 'Maharashtra'),
        );
        $mumbai = $this->seller->requireForLocation(
            $this->issuer->require(StatutorySupplyKind::Product, 'MUMBAI', '07AAAAA0000A1Z5', 'Delhi'),
        );

        $this->assertSame($this->configuredSellerGstin('delhi'), $delhi->gstin);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $mumbai->gstin);
        $this->assertSame('Phil Technologies (P) Limited', $delhi->legalName);
        $this->assertSame($delhi->legalName, $mumbai->legalName);
        $this->assertNotSame($delhi->gstin, $mumbai->gstin);
    }

    public function test_pos_delhi_product_keeps_delhi_gstin_for_maharashtra_customer(): void
    {
        $invoice = $this->issuePosProduct('DELHI-RETAIL', '27AAAAA0000A1Z5', 'Maharashtra', 'DL-MH');

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
    }

    public function test_pos_mumbai_product_keeps_mumbai_gstin_for_delhi_customer(): void
    {
        $invoice = $this->issuePosProduct('MUMBAI', '07AAAAA0000A1Z5', 'Delhi', 'MU-DL');

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertSame('Delhi', $invoice->place_of_supply_state);
    }

    public function test_missing_issuer_address_fails_closed(): void
    {
        config(['statutory_invoices.location_series.locations.mumbai.address' => '']);

        try {
            $this->invoices->mint($this->request('missing-address', StatutoryLocationSeries::MUMBAI), $this->actor);
            $this->fail('Expected missing issuer address to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('address', implode(' ', $exception->errors()['seller'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_missing_issuer_gstin_fails_closed(): void
    {
        config(['statutory_invoices.location_series.locations.delhi.gstin' => '']);

        try {
            $this->invoices->mint($this->request('missing-gstin', StatutoryLocationSeries::DELHI), $this->actor);
            $this->fail('Expected missing issuer GSTIN to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('GSTIN', implode(' ', $exception->errors()['seller'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_unsupported_third_gst_registration_fails_closed(): void
    {
        config([
            'statutory_invoices.location_series.locations.delhi.gstin' => '09AAICP1128M1Z5',
        ]);

        try {
            $this->seller->requireForLocation(StatutoryLocationSeries::DELHI);
            $this->fail('Expected an out-of-scope GSTIN to refuse the Delhi issuer.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('GST state', implode(' ', $exception->errors()['seller'] ?? []));
        }

        try {
            $this->seller->requireForLocation('chennai');
            $this->fail('Expected an unsupported location to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Delhi and Mumbai', implode(' ', $exception->errors()['seller'] ?? []));
        }
    }

    public function test_global_gstin_scope_is_not_used_as_the_invoice_seller_gstin(): void
    {
        config(['statutory_invoices.gstin_scope' => $this->configuredSellerGstin('delhi')]);

        $invoice = $this->invoices->mint($this->request('mumbai-seller', StatutoryLocationSeries::MUMBAI), $this->actor);

        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertNotSame(config('statutory_invoices.gstin_scope'), $invoice->seller_gstin);
    }

    public function test_unknown_product_branch_fails_closed_before_seller_identity(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require(StatutorySupplyKind::Product, 'CHENNAI', null, null);
    }

    private function issuePosProduct(
        string $branchCode,
        string $buyerGstin,
        string $placeOfSupply,
        string $serialSuffix,
    ): StatutoryInvoice {
        $branch = InventoryBranch::query()->create([
            'code' => $branchCode,
            'name' => $branchCode,
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$serialSuffix,
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $serial = 'SELLER-'.$serialSuffix;
        app(InventoryStockService::class)->stockInSerialized($product, $branch, [$serial], $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000088'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => $buyerGstin,
                'place_of_supply_state' => $placeOfSupply,
            ],
        );

        return $this->invoices->issueFromPosSale($sale, $this->actor);
    }

    private function request(string $sourceId, string $location): StatutoryInvoiceMintRequest
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
            financialYearToken: '2026-2027',
        );
    }
}
