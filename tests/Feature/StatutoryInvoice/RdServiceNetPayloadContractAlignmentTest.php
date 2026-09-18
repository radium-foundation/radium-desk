<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceLinePresentation;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RdServiceNetPayloadContractAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-rdservice-net-secret';

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->configureLocationSellerIdentity();

        config([
            'channel_ingest.secrets.rdservice_net' => self::SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
            'statutory_invoices.location_series.enabled' => true,
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.mumbai.gstin' => '27AAICP1128M1Z7',
        ]);
    }

    public function test_aligned_payload_with_seller_gstin_and_gst_percentage_is_invoice_eligible_on_ingest(): void
    {
        $payload = $this->netPayload('RN2');
        $this->signedPost($payload)->assertCreated()->assertJsonPath('invoice_eligible', true);

        $order = CommerceOrder::query()->where('source_id', 'RN2')->firstOrFail();
        $this->assertSame('07AAICP1128M1Z9', $order->seller_gstin);
        $this->assertTrue($order->invoice_eligible);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_b2b_rn60_shape_mints_delhi_b2b_number(): void
    {
        $this->signedPost($this->netPayload('RN60', [
            'customer' => [
                'name' => 'B2B Buyer',
                'phone' => '9000000060',
                'gstin' => '10DHCPK7361A1ZZ',
                'billing_address' => [
                    'line1' => 'Patna Road',
                    'city' => 'Patna',
                    'state' => 'Bihar',
                    'pincode' => '800001',
                ],
            ],
            'place_of_supply_state' => 'Bihar',
            'lines' => [
                $this->serviceLine(744.92, 134.08, 879),
                $this->serviceLine(169.49, 30.51, 200, 'AMC : 1-Year Comprehensive', sku: null, amcid: 42),
                $this->zeroCallbackLine(),
            ],
        ]))->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RN60')->firstOrFail();
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('10DHCPK7361A1ZZ', $invoice->buyer_gstin);
        $this->assertStringStartsWith('INV-0767', $invoice->invoice_number);
        $this->assertSame(2, StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_b2b_rn68_shape_mints_delhi_b2b_number(): void
    {
        $this->signedPost($this->netPayload('RN68', [
            'customer' => [
                'name' => 'B2B Buyer',
                'phone' => '9000000068',
                'gstin' => '19AABAV4239J1Z5',
                'billing_address' => [
                    'line1' => 'Kolkata Road',
                    'city' => 'Kolkata',
                    'state' => 'West Bengal',
                    'pincode' => '700001',
                ],
            ],
            'place_of_supply_state' => 'West Bengal',
            'lines' => [
                $this->serviceLine(422.88, 76.12, 499),
                $this->zeroCallbackLine(),
            ],
        ]))->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RN68')->firstOrFail();
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('19AABAV4239J1Z5', $invoice->buyer_gstin);
        $this->assertStringStartsWith('INV-0767', $invoice->invoice_number);
    }

    public function test_zero_callback_line_is_suppressed_on_statutory_mint(): void
    {
        $this->signedPost($this->netPayload('RN2', [
            'place_of_supply_state' => 'Madhya Pradesh',
            'customer' => [
                'name' => 'Buyer',
                'phone' => '9000000002',
                'billing_address' => [
                    'line1' => '1 Road',
                    'city' => 'Bhopal',
                    'state' => 'Madhya Pradesh',
                    'pincode' => '462001',
                ],
            ],
            'lines' => [
                $this->serviceLine(422.88, 76.12, 499),
                $this->zeroCallbackLine(),
            ],
        ]))->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RN2')->firstOrFail();
        $presenter = app(StatutoryInvoiceCommerceLinePresentation::class);
        $callback = $order->items->last();
        $this->assertFalse($presenter->includesOnStatutoryInvoice($callback));

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $this->assertSame(1, StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->count());
        $this->assertStringStartsWith('INV-67', $invoice->invoice_number);
    }

    public function test_dadra_legacy_state_name_is_accepted_when_normalized(): void
    {
        $this->signedPost($this->netPayload('RN23', [
            'place_of_supply_state' => 'Dadra and Nagar Haveli and Daman and Diu',
            'customer' => [
                'name' => 'Buyer',
                'phone' => '9000000023',
                'billing_address' => [
                    'line1' => '1 Road',
                    'city' => 'Silvassa',
                    'state' => 'Dadra and Nagar Haveli and Daman and Diu',
                    'pincode' => '396230',
                ],
            ],
        ]))->assertCreated()->assertJsonPath('invoice_eligible', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function netPayload(string $sourceId, array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => StatutoryInvoiceChannel::RdServiceNet->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'cf_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => '2026-09-10 07:32:44',
            'paid_at' => '2026-09-10 07:32:44',
            'customer' => [
                'name' => 'Buyer',
                'phone' => '9000000001',
                'email' => 'buyer@example.com',
                'gstin' => null,
                'billing_address' => [
                    'line1' => '1 Test Street',
                    'city' => 'New Delhi',
                    'state' => 'Delhi',
                    'pincode' => '110001',
                ],
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'lines' => [
                $this->serviceLine(100, 18, 118),
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceLine(
        float $taxable,
        float $tax,
        float $total,
        string $description = 'Information technology (IT) consulting & support services',
        ?string $sku = 'RD-SVC',
        ?int $amcid = null,
    ): array {
        $line = [
            'description' => $description,
            'qty' => 1,
            'unit_price' => $taxable,
            'hsn_sac' => '998313',
            'gst_percentage' => round($taxable > 0 ? ($tax / $taxable) * 100 : 0, 2),
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $total,
        ];

        if ($sku !== null) {
            $line['sku'] = $sku;
        }

        if ($amcid !== null) {
            $line['amcid'] = $amcid;
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroCallbackLine(): array
    {
        return [
            'description' => 'Call Back - Not Required',
            'qty' => 1,
            'unit_price' => 0,
            'hsn_sac' => '998313',
            'gst_percentage' => 0,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceNet->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, $secret),
            'HTTP_IDEMPOTENCY_KEY' => 'statutory:rdservice_net:commerce_order:'.($payload['source_id'] ?? ''),
        ], $body);
    }
}
