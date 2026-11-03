<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InvoiceSequenceAllocation;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryBillingAddressRemediationTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

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
            'statutory_invoices.billing_address_max_length' => 65535,
            'channel_ingest.secrets.radiumbox_com' => 'test-radiumbox-secret',
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_migration_widens_statutory_invoices_billing_address_column(): void
    {
        $column = Schema::getColumnType('statutory_invoices', 'billing_address');

        $this->assertSame('text', $column);
    }

    public function test_mint_persists_flat_billing_address_longer_than_255_characters(): void
    {
        $flat = str_repeat('C', 273);

        $invoice = $this->invoices->mint($this->mintRequest('long-flat-273', billingAddress: $flat), $this->actor);

        $this->assertSame(273, strlen((string) $invoice->billing_address));
        $this->assertSame($flat, $invoice->fresh()->billing_address);
    }

    public function test_mint_applies_composer_and_persists_rbp1697_shaped_address_under_prior_varchar_limit(): void
    {
        $structured = [
            'line1' => 'Adarsha nagar, opposite ayyappa swamy temple, village : CHENNUR, MANDAL : CHENNUR, district : MANCHERIAL, srate: telangana - 504201',
            'line2' => 'Opposite ayyappa swamy temple, village CHENNUR, mandal CHENNUR, DISTRICT MANCHERIAL, STATE TELANGANA - 504201',
            'city' => 'Kairmnagar',
            'state' => 'Telangana',
            'pincode' => '504201',
        ];
        $legacyFlat = str_repeat('X', 273);

        $invoice = $this->invoices->mint(
            $this->mintRequest('rbp1697-shape', billingAddress: $legacyFlat, billingStructured: $structured),
            $this->actor,
        );

        $expectedFlat = StatutoryBillingStructured::flattenForPersistence($structured);

        $this->assertSame($expectedFlat, $invoice->billing_address);
        $this->assertLessThan(255, strlen((string) $invoice->billing_address));
        $this->assertArrayNotHasKey('line2', $invoice->billing_address_structured ?? []);
        $this->assertTrue(StatutoryBillingStructured::isCompleteForIrn($invoice->billing_address_structured));
    }

    public function test_channel_ingest_composes_billing_address_and_structured_snapshot(): void
    {
        $structured = [
            'line1' => 'Adarsha nagar, opposite ayyappa swamy temple, village : CHENNUR, MANDAL : CHENNUR, district : MANCHERIAL, srate: telangana - 504201',
            'line2' => 'Opposite ayyappa swamy temple, village CHENNUR, mandal CHENNUR, DISTRICT MANCHERIAL, STATE TELANGANA - 504201',
            'city' => 'Kairmnagar',
            'state' => 'Telangana',
            'pincode' => '504201',
        ];
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE9031697',
            'source_order_id' => 'RDE9031697',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_RDE9031697',
            'currency' => 'INR',
            'customer' => ['name' => 'Buyer', 'phone' => '9000000099'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Telangana',
            'billing_address' => $structured,
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, 'test-radiumbox-secret'),
        ], $body)->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RDE9031697')->firstOrFail();

        $this->assertLessThan(255, strlen((string) $order->billing_address));
        $this->assertArrayNotHasKey('line2', $order->billing_address_structured ?? []);
    }

    public function test_mint_validation_failure_does_not_allocate_invoice_number(): void
    {
        config(['statutory_invoices.billing_address_max_length' => 120]);
        $flat = str_repeat('D', 150);

        try {
            $this->invoices->mint($this->mintRequest('too-long', billingAddress: $flat), $this->actor);
            $this->fail('Expected billing address validation at mint.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
    }

    public function test_pdf_locality_line_is_null_when_locality_is_already_in_long_flat_address(): void
    {
        $structured = [
            'line1' => str_repeat('E', 260),
            'city' => 'Kairmnagar',
            'state' => 'Telangana',
            'pincode' => '504201',
        ];
        $flat = StatutoryBillingStructured::flattenForPersistence($structured);

        $this->assertGreaterThan(255, strlen($flat));
        $this->assertNull(StatutoryBillingStructured::pdfLocalityLine($structured, $flat));
    }

    private function mintRequest(
        string $sourceId,
        ?string $billingAddress = null,
        ?array $billingStructured = null,
    ): StatutoryInvoiceMintRequest {
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
            numberingLocation: StatutoryLocationSeries::DELHI,
            financialYearToken: '2026-2027',
            billingAddress: $billingAddress,
            billingAddressStructured: $billingStructured,
        );
    }
}
