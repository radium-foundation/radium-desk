<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentP3InvoiceTest extends TestCase
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

    public function test_cannot_issue_from_ready_for_fulfilment(): void
    {
        $fulfilment = $this->ingestHardware('RDE900301');
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        try {
            $this->invoices->issueInvoice($fulfilment->fresh());
            $this->fail('READY must not issue.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SERIALS_ALLOCATED', implode(' ', $exception->errors()['invoice'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_cannot_issue_without_serials_allocated(): void
    {
        $fulfilment = $this->ingestHardware('RDE900302');
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');

        $this->expectException(ValidationException::class);
        $this->invoices->issueInvoice($fulfilment);
    }

    public function test_invoice_receives_the_exact_allocated_serial_list(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900303', 'DELHI-RETAIL', 2);
        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame(
            ['SN-RDE900303-001', 'SN-RDE900303-002'],
            $this->workflow->allocatedSerialNumbers($fulfilment->fresh()),
        );
        $this->assertSame(
            ['SN-RDE900303-001', 'SN-RDE900303-002'],
            $fulfilment->fresh()->metadata['invoice_serials'] ?? [],
        );
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);
        $this->assertStringContainsString('SN-RDE900303-001', $pdf);
        $this->assertStringContainsString('SN-RDE900303-002', $pdf);
        $this->assertSame(HardwareFulfilmentState::InvoiceIssued, $fulfilment->fresh()->state);
    }

    public function test_one_to_five_serials_render_inline(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900304', 'DELHI-RETAIL', 5);
        $invoice = $this->invoices->issueInvoice($fulfilment);
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertStringContainsString('Serial Numbers', $pdf);
        $this->assertStringContainsString('SN-RDE900304-001', $pdf);
        $this->assertStringContainsString('SN-RDE900304-005', $pdf);
        $this->assertStringNotContainsString('See Annexure A', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
    }

    public function test_more_than_five_serials_use_annexure_a_in_the_same_pdf(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900305', 'DELHI-RETAIL', 6);
        $invoice = $this->invoices->issueInvoice($fulfilment);
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertStringContainsString('Serial Numbers', $pdf);
        $this->assertStringContainsString($invoice->invoice_number, $pdf);
        $this->assertStringContainsString('RDE900305', $pdf);
        $this->assertStringNotContainsString('statutory:radiumbox_com', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertStringNotContainsString('See Annexure A', $pdf);
        $this->assertSame(1, substr_count($pdf, '%PDF-1.4'));
        for ($i = 1; $i <= 6; $i++) {
            $this->assertStringContainsString(sprintf('SN-RDE900305-%03d', $i), $pdf);
        }
    }

    public function test_two_hundred_serials_render_without_truncation_or_duplication(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900306', 'DELHI-RETAIL', 200);
        $invoice = $this->invoices->issueInvoice($fulfilment);
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertStringContainsString('Serial Numbers', $pdf);
        $this->assertStringNotContainsString('ANNEXURE A', $pdf);
        $this->assertMatchesRegularExpression('/\\/Count [2-9]\\d*/', $pdf);
        $seen = [];
        for ($i = 1; $i <= 200; $i++) {
            $serial = sprintf('SN-RDE900306-%03d', $i);
            $this->assertStringContainsString($serial, $pdf);
            $this->assertArrayNotHasKey($serial, $seen);
            $seen[$serial] = true;
        }
        $this->assertCount(200, $seen);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_duplicate_serials_fail_closed(): void
    {
        $fulfilment = $this->ingestHardware('RDE900307');
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $item = $fulfilment->commerceOrder?->items->first();
        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_item_id' => $item?->id,
            'line_no' => 1,
            'position' => 1,
            'serial_number' => 'DUP-SERIAL',
            'status' => HardwareFulfilmentSerialStatus::Allocated,
            'allocated_at' => now(),
        ]);
        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_item_id' => $item?->id,
            'line_no' => 1,
            'position' => 2,
            'serial_number' => 'dup-serial',
            'status' => HardwareFulfilmentSerialStatus::Allocated,
            'allocated_at' => now(),
        ]);

        try {
            $this->invoices->issueInvoice($fulfilment->fresh());
            $this->fail('Duplicate serials must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Duplicate', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_serial_count_mismatch_fails_closed(): void
    {
        $fulfilment = $this->ingestHardware('RDE900308', qty: 2);
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), 1);

        try {
            $this->invoices->issueInvoice($fulfilment->fresh());
            $this->fail('Qty mismatch must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not match physical quantity', implode(' ', $exception->errors()['serials'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_retry_is_idempotent(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900309', 'DELHI-RETAIL', 1);
        $first = $this->invoices->issueInvoice($fulfilment);
        $second = $this->invoices->issueInvoice($fulfilment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->invoice_number, $second->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_concurrent_issuance_cannot_create_duplicate_invoices(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900310', 'DELHI-RETAIL', 1);
        $service = app(HardwareFulfilmentInvoiceService::class);

        $a = $service->issueInvoice($fulfilment);
        $b = $service->issueInvoice($fulfilment->fresh());

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RDE900310')->count());
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE900310', $a->idempotency_key);
    }

    public function test_delhi_b2c_uses_inv_671(): void
    {
        $invoice = $this->invoices->issueInvoice(
            $this->prepareIssuable('RDE900311', 'DELHI-RETAIL', 1, placeOfSupply: 'Delhi'),
        );

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame('07AAICP1128M1Z9', $invoice->seller_gstin);
        $this->assertNull($invoice->buyer_gstin);
    }

    public function test_delhi_b2b_uses_inv_07671(): void
    {
        $invoice = $this->invoices->issueInvoice($this->prepareIssuable(
            'RDE900312',
            'DELHI-RETAIL',
            1,
            buyerGstin: '07AAAAA0000A1Z5',
            placeOfSupply: 'Delhi',
        ));

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame('07AAICP1128M1Z9', $invoice->seller_gstin);
        $this->assertSame('07AAAAA0000A1Z5', $invoice->buyer_gstin);
    }

    public function test_mumbai_b2c_and_b2b_use_inv_27671(): void
    {
        $b2c = $this->invoices->issueInvoice(
            $this->prepareIssuable('RDE900313', 'MUMBAI', 1, placeOfSupply: 'Maharashtra'),
        );
        $b2b = $this->invoices->issueInvoice($this->prepareIssuable(
            'RDE900314',
            'MUMBAI',
            1,
            buyerGstin: '27AAAAA0000A1Z5',
            placeOfSupply: 'Maharashtra',
        ));

        $this->assertSame('INV-27671', $b2c->invoice_number);
        $this->assertSame('INV-27672', $b2b->invoice_number);
        $this->assertSame('27AAICP1128M1Z7', $b2c->seller_gstin);
        $this->assertSame('27AAICP1128M1Z7', $b2b->seller_gstin);
    }

    public function test_maharashtra_customer_delhi_stock_is_delhi_issuer_with_igst(): void
    {
        $invoice = $this->invoices->issueInvoice(
            $this->prepareIssuable('RDE900315', 'DELHI-RETAIL', 1, placeOfSupply: 'Maharashtra'),
        );

        $this->assertSame('INV-671', $invoice->invoice_number);
        $this->assertSame('07AAICP1128M1Z9', $invoice->seller_gstin);
        $this->assertSame('0.00', (string) $invoice->cgst);
        $this->assertSame('0.00', (string) $invoice->sgst);
        $this->assertSame('465.10', (string) $invoice->igst);
    }

    public function test_pos_product_issuer_remains_delhi_not_delhi_b2c(): void
    {
        $this->invoices->issueInvoice(
            $this->prepareIssuable('RDE900316', 'DELHI-RETAIL', 1, placeOfSupply: 'Delhi'),
        );

        $this->assertSame(
            StatutoryLocationSeries::DELHI,
            app(StatutoryBillingIssuer::class)->requireForProductBranch('DELHI-RETAIL'),
        );
        $this->assertNotSame(
            StatutoryLocationSeries::DELHI_B2C,
            app(StatutoryBillingIssuer::class)->requireForProductBranch('DELHI-RETAIL'),
        );
    }

    public function test_frozen_pending_orders_are_not_invoiced(): void
    {
        $this->assertSame([
            'RDE318360',
            'RDE318367',
            'RDE318378',
            'RDE318379',
            'RDE318382',
            'RDE318388',
            'RDE318391',
        ], HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS);

        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
        }

        $this->assertSame(0, CommerceOrder::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
        $this->assertSame(0, StatutoryInvoice::query()->whereIn('source_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count());
    }

    public function test_bundled_rd_stays_an_annotation_on_the_hardware_line(): void
    {
        $fulfilment = $this->prepareIssuable('RDE900317', 'DELHI-RETAIL', 1, placeOfSupply: 'Delhi', rdserviceid: 88);
        $invoice = $this->invoices->issueInvoice($fulfilment);

        $this->assertSame(1, $invoice->items->count());
        $this->assertStringContainsString('bundled RD #88', (string) $invoice->items->first()?->description);
        $this->assertSame('84716050', $invoice->items->first()?->hsn_sac);
        $this->assertSame(3049.0, (float) $invoice->items->first()?->line_total);
    }

    public function test_mantra_mfs_invoice_and_pdf_use_canonical_variant_description(): void
    {
        $fulfilment = $this->prepareIssuable(
            'RDE900318',
            'DELHI-RETAIL',
            1,
            placeOfSupply: 'Delhi',
            rdserviceid: 1119,
            modelId: 946,
            description: 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
            amcid: 1120,
            otgid: 1126,
        );
        $invoice = $this->invoices->issueInvoice($fulfilment);
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertSame('Mantra MFS 110 1R 1W U', (string) $invoice->items->first()?->description);
        $this->assertStringNotContainsString('bundled RD #', (string) $invoice->items->first()?->description);
        $this->assertStringContainsString('Mantra MFS 110 1R 1W U', $pdf);
        $this->assertStringNotContainsString('100 / 110', $pdf);
        $this->assertSame(3049.0, (float) $invoice->items->first()?->line_total);
        $this->assertSame('84716050', $invoice->items->first()?->hsn_sac);
    }

    private function prepareIssuable(
        string $sourceId,
        string $branchCode,
        int $qty,
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Madhya Pradesh',
        ?int $rdserviceid = null,
        int $modelId = 951,
        string $description = 'MSO1300',
        ?int $amcid = null,
        ?int $otgid = null,
    ): HardwareFulfilment {
        $fulfilment = $this->ingestHardware(
            $sourceId,
            $qty,
            $buyerGstin,
            $placeOfSupply,
            $rdserviceid,
            $modelId,
            $description,
            $amcid,
            $otgid,
        );
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);
        $this->createAllocatedSerials($fulfilment->fresh(), $qty);

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

    private function ingestHardware(
        string $sourceId,
        int $qty = 1,
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Madhya Pradesh',
        ?int $rdserviceid = null,
        int $modelId = 951,
        string $description = 'MSO1300',
        ?int $amcid = null,
        ?int $otgid = null,
    ): HardwareFulfilment {
        $taxable = round(2583.90 * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $lineTotal = round($taxable + $tax, 2);
        $line = [
            'description' => $description,
            'sku' => (string) $modelId,
            'qty' => $qty,
            'unit_price' => 3049,
            'hsn_sac' => '84716050',
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $lineTotal,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => $modelId,
            'rdserviceid' => $rdserviceid,
        ];
        if ($amcid !== null) {
            $line['amcid'] = $amcid;
        }
        if ($otgid !== null) {
            $line['otgid'] = $otgid;
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
            'lines' => [$line],
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
