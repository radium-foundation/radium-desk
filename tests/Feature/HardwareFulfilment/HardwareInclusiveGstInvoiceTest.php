<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\StatutoryInvoice\GstSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareInclusiveGstInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentInvoiceService $invoices;

    private HardwareFulfilmentWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->invoices = app(HardwareFulfilmentInvoiceService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
    }

    public function test_qty_one_2499_keeps_stored_taxable_and_issues_igst(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901901', [
            'qty' => 1,
            'unit_price' => 2499.00,
            'taxable_value' => 2117.80,
            'tax_total' => 381.20,
            'line_total' => 2499.00,
        ], placeOfSupply: 'Maharashtra');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('2117.80', (string) $invoice->taxable_value);
        $this->assertSame('381.20', (string) $invoice->tax_total);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame('0.00', (string) $invoice->sgst);
        $this->assertSame('381.20', (string) $invoice->igst);
        $this->assertSame('2499.00', (string) $invoice->invoice_value);
        $this->assertSame('18.00', (string) $invoice->items->first()?->gst_percentage);
        $this->assertSame('2117.80', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
        $this->assertSame('381.20', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    public function test_qty_ten_one_paisa_inclusive_projects_invoice_from_gross_without_rewriting_commerce(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901902', [
            'qty' => 10,
            'unit_price' => 2499.00,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ], buyerGstin: '29AAAAA0000A1Z5', placeOfSupply: 'Karnataka');

        $invoice = $this->invoices->issueInvoice($fulfilment);
        $item = $fulfilment->fresh()->commerceOrder?->items->first();

        $this->assertSame('21177.97', (string) $invoice->taxable_value);
        $this->assertSame('3812.03', (string) $invoice->tax_total);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame('0.00', (string) $invoice->sgst);
        $this->assertSame('3812.03', (string) $invoice->igst);
        $this->assertSame('24990.00', (string) $invoice->invoice_value);
        $this->assertSame('18.00', (string) $invoice->items->first()?->gst_percentage);
        $this->assertSame(2499000, (int) round((float) $invoice->taxable_value * 100) + (int) round((float) $invoice->tax_total * 100));
        $this->assertSame('21177.96', (string) $item?->taxable_value);
        $this->assertSame('3812.04', (string) $item?->tax_total);
        $this->assertNull($item?->gst_percentage);
        $this->assertSame(10, $fulfilment->fresh()->serials()->count());
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_qty_five_12495_continues_to_pass(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901903', [
            'qty' => 5,
            'unit_price' => 2499.00,
            'taxable_value' => 10588.98,
            'tax_total' => 1906.02,
            'line_total' => 12495.00,
        ], placeOfSupply: 'Maharashtra');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('10588.98', (string) $invoice->taxable_value);
        $this->assertSame('1906.02', (string) $invoice->tax_total);
        $this->assertSame('1906.02', (string) $invoice->igst);
        $this->assertSame('12495.00', (string) $invoice->invoice_value);
        $this->assertSame('10588.98', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
    }

    public function test_rde318435_values_issue_igst_from_gross_residual(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901908', [
            'qty' => 1,
            'unit_price' => 3849.00,
            'taxable_value' => 3261.87,
            'tax_total' => 587.13,
            'line_total' => 3849.00,
        ], placeOfSupply: 'Tamil Nadu');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('3261.86', (string) $invoice->taxable_value);
        $this->assertSame('587.14', (string) $invoice->tax_total);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame('0.00', (string) $invoice->sgst);
        $this->assertSame('587.14', (string) $invoice->igst);
        $this->assertSame('3849.00', (string) $invoice->invoice_value);
        $this->assertSame('18.00', (string) $invoice->items->first()?->gst_percentage);
        $this->assertSame('3261.87', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
        $this->assertSame('587.13', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    public function test_rde318401_values_issue_igst_from_gross_residual(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901909', [
            'qty' => 1,
            'unit_price' => 2999.00,
            'taxable_value' => 2541.52,
            'tax_total' => 457.48,
            'line_total' => 2999.00,
        ], placeOfSupply: 'Assam');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('2541.53', (string) $invoice->taxable_value);
        $this->assertSame('457.47', (string) $invoice->tax_total);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame('0.00', (string) $invoice->sgst);
        $this->assertSame('457.47', (string) $invoice->igst);
        $this->assertSame('2999.00', (string) $invoice->invoice_value);
        $this->assertSame('2541.52', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
        $this->assertSame('457.48', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    public function test_rin_2649_values_issue_with_explicit_eighteen_percent(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901910', [
            'qty' => 1,
            'unit_price' => 2649.00,
            'taxable_value' => 2244.92,
            'tax_total' => 404.08,
            'line_total' => 2649.00,
            'gst_percentage' => 18,
        ], placeOfSupply: 'West Bengal');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('2244.92', (string) $invoice->taxable_value);
        $this->assertSame('404.08', (string) $invoice->tax_total);
        $this->assertSame('404.08', (string) $invoice->igst);
        $this->assertSame('2649.00', (string) $invoice->invoice_value);
        $this->assertSame('18.00', (string) $invoice->items->first()?->gst_percentage);
        $this->assertSame('2244.92', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
        $this->assertSame('404.08', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    public function test_rde318435_delhi_intra_state_splits_projected_gst(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901911', [
            'qty' => 1,
            'unit_price' => 3849.00,
            'taxable_value' => 3261.87,
            'tax_total' => 587.13,
            'line_total' => 3849.00,
        ], placeOfSupply: 'Delhi');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('3261.86', (string) $invoice->taxable_value);
        $this->assertSame('587.14', (string) $invoice->tax_total);
        $this->assertSame('293.57', (string) $invoice->cgst);
        $this->assertSame('293.57', (string) $invoice->sgst);
        $this->assertSame('0.00', (string) $invoice->igst);
        $this->assertSame('3849.00', (string) $invoice->invoice_value);
        $this->assertSame(587.14, round((float) $invoice->cgst + (float) $invoice->sgst, 2));
    }

    public function test_qty_ten_delhi_intra_state_splits_cgst_sgst(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901904', [
            'qty' => 10,
            'unit_price' => 2499.00,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ], placeOfSupply: 'Delhi');

        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame('21177.97', (string) $invoice->taxable_value);
        $this->assertSame('3812.03', (string) $invoice->tax_total);
        $this->assertSame('1906.02', (string) $invoice->cgst);
        $this->assertSame('1906.01', (string) $invoice->sgst);
        $this->assertSame('0.00', (string) $invoice->igst);
        $this->assertSame('24990.00', (string) $invoice->invoice_value);
        $this->assertSame(3812.03, round((float) $invoice->cgst + (float) $invoice->sgst, 2));
    }

    public function test_gst_mismatch_beyond_one_paisa_still_fails(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901905', [
            'qty' => 1,
            'unit_price' => 3049.00,
            'taxable_value' => 2583.90,
            'tax_total' => 50.00,
            'line_total' => 3049.00,
            'gst_percentage' => 18,
        ]);

        try {
            $this->invoices->issueInvoice($fulfilment);
            $this->fail('Expected a GST mismatch larger than one paisa to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertSame([GstSplitService::TAX_MISMATCH], $exception->errors()['gst'] ?? []);
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
        $this->assertSame('50.00', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->tax_total);
    }

    public function test_qty_ten_retry_is_idempotent(): void
    {
        $fulfilment = $this->prepareIssuable('RDE901906', [
            'qty' => 10,
            'unit_price' => 2499.00,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ], buyerGstin: '29AAAAA0000A1Z5', placeOfSupply: 'Karnataka');

        $first = $this->invoices->issueInvoice($fulfilment);
        $second = $this->invoices->issueInvoice($fulfilment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->invoice_number, $second->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_qty_ten_serial_count_mismatch_still_fails_before_gst(): void
    {
        $fulfilment = $this->ingestHardware('RDE901907', [
            'qty' => 10,
            'unit_price' => 2499.00,
            'taxable_value' => 21177.96,
            'tax_total' => 3812.04,
            'line_total' => 24990.00,
        ]);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), 9);

        try {
            $this->invoices->issueInvoice($fulfilment->fresh());
            $this->fail('Qty mismatch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not match physical quantity', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame('21177.96', (string) $fulfilment->fresh()->commerceOrder?->items->first()?->taxable_value);
    }

    /**
     * @param  array{qty: int, unit_price: float, taxable_value: float, tax_total: float, line_total: float, gst_percentage?: float}  $line
     */
    private function prepareIssuable(
        string $sourceId,
        array $line,
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Madhya Pradesh',
    ): HardwareFulfilment {
        $fulfilment = $this->ingestHardware($sourceId, $line, $buyerGstin, $placeOfSupply);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), (int) $line['qty']);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function assignBranch(HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();

        return $branch;
    }

    private function createAllocatedSerials(HardwareFulfilment $fulfilment, int $qty): void
    {
        $item = $fulfilment->commerceOrder?->items->first();
        for ($position = 1; $position <= $qty; $position++) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item?->id,
                'line_no' => 1,
                'position' => $position,
                'serial_number' => sprintf('SN-%s-%03d', $fulfilment->source_id, $position),
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array{qty: int, unit_price: float, taxable_value: float, tax_total: float, line_total: float, gst_percentage?: float}  $line
     */
    private function ingestHardware(
        string $sourceId,
        array $line,
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Madhya Pradesh',
    ): HardwareFulfilment {
        $payloadLine = [
            'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
            'sku' => '946',
            'qty' => $line['qty'],
            'unit_price' => $line['unit_price'],
            'hsn_sac' => '84716050',
            'taxable_value' => $line['taxable_value'],
            'tax_total' => $line['tax_total'],
            'line_total' => $line['line_total'],
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 946,
        ];
        if (array_key_exists('gst_percentage', $line)) {
            $payloadLine['gst_percentage'] = $line['gst_percentage'];
        }

        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
                'gstin' => $buyerGstin,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => $placeOfSupply,
            'lines' => [$payloadLine],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body)->assertCreated();

        return HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    }
}
