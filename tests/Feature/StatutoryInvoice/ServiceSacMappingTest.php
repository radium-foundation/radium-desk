<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceSacMappingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-rdservice-in-secret';

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 18:28:19');
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.rdservice_in' => self::SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ingest_corrects_rd_service_spoke_sac_without_a_generic_default(): void
    {
        $payload = $this->ingestPayload('RD-SAC-INGEST', [
            [
                'description' => 'Information technology (IT) consulting & support services (SAC - 998313)',
                'sku' => 'RD-SVC',
                'qty' => 1,
                'unit_price' => 422.88,
                'hsn_sac' => '998314',
                'gst_percentage' => 18,
                'taxable_value' => 422.88,
                'tax_total' => 76.12,
                'line_total' => 499,
            ],
            [
                'description' => 'Future Service A',
                'sku' => 'FUTURE-A',
                'qty' => 1,
                'unit_price' => 100,
                'hsn_sac' => '998399',
                'gst_percentage' => 18,
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ],
        ]);

        $this->signedPost($payload)->assertCreated();

        $order = CommerceOrder::query()->where('source_id', 'RD-SAC-INGEST')->firstOrFail();
        $this->assertSame('998313', $order->items[0]->hsn_sac);
        $this->assertSame('998399', $order->items[1]->hsn_sac);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_rd_service_invoice_and_pdf_use_998313_and_leave_other_services_alone(): void
    {
        $order = $this->commerceOrder('RD-SAC-MINT', [
            [
                'description' => 'Information technology (IT) consulting & support services (SAC - 998313) - 1 Year Unlimited',
                'hsn_sac' => '998314',
                'unit_price' => 422.88,
                'taxable_value' => 422.88,
                'tax_total' => 76.12,
                'line_total' => 499,
            ],
            [
                'description' => 'Future Service A',
                'hsn_sac' => '998399',
                'unit_price' => 100,
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ],
        ], taxable: 522.88, tax: 94.12, total: 617);

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertSame('998313', $invoice->items[0]->hsn_sac);
        $this->assertSame('998399', $invoice->items[1]->hsn_sac);
        $this->assertSame('998314', $order->fresh('items')->items[0]->hsn_sac);
        $this->assertStringContainsString('998313', $pdf);
        $this->assertStringContainsString('998399', $pdf);
        $this->assertStringContainsString('SAC - 998313', $pdf);
    }

    public function test_existing_issued_invoice_sac_is_not_rewritten(): void
    {
        $order = $this->commerceOrder('RD-SAC-HIST', [[
            'description' => 'RD Service',
            'hsn_sac' => '998314',
            'unit_price' => 422.88,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]]);
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);
        $this->assertSame('998313', $invoice->items[0]->hsn_sac);

        StatutoryInvoiceItem::query()->where('invoice_id', $invoice->id)->update(['hsn_sac' => '998314']);

        $replay = $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);

        $this->assertSame($invoice->id, $replay->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('998314', $replay->items[0]->hsn_sac);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function commerceOrder(string $sourceId, array $lines, float $taxable = 422.88, float $tax = 76.12, float $total = 499): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'CHANDRAKANT GANPAT SARODE',
            'billing_state' => 'Maharashtra',
            'billing_address' => 'Bhiwandi, Maharashtra',
            'branch_code' => 'MUMBAI',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'order_value' => $total,
            'ordered_at' => '2026-09-07 10:00:00',
            'received_at' => now(),
        ]);
        foreach ($lines as $index => $line) {
            $order->items()->create([
                'line_no' => $index + 1,
                'description' => $line['description'],
                'hsn_sac' => $line['hsn_sac'],
                'qty' => 1,
                'unit_price' => $line['unit_price'],
                'gst_percentage' => 18,
                'taxable_value' => $line['taxable_value'],
                'tax_total' => $line['tax_total'],
                'line_total' => $line['line_total'],
            ]);
        }

        return $order->fresh(['items']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function ingestPayload(string $sourceId, array $lines): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
                'gstin' => null,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
            'place_of_supply_state' => 'Maharashtra',
            'billing_state' => 'Maharashtra',
            'branch_code' => 'MUMBAI',
            'ordered_at' => '2026-09-07 10:00:00',
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceIn->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::SECRET),
        ], $body);
    }
}
