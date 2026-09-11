<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\EInvoiceRecord;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentSerialCorrectionService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentSerialCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private HardwareFulfilmentSerialCorrectionService $corrections;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.sku_map' => [],
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->corrections = app(HardwareFulfilmentSerialCorrectionService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-MSO-TEST',
            'name' => 'Desk MSO test product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->mapModel(951);
    }

    public function test_corrects_allocated_invoice_serial_inventory_and_pdf_without_changing_financials(): void
    {
        $fulfilment = $this->issuedFulfilment('RDE900801', 'DELHI-RETAIL', 'SN-WRONG-801', 'SN-RIGHT-801');
        $invoice = StatutoryInvoice::query()->findOrFail($fulfilment->statutory_invoice_id);
        $beforeTotal = (float) $invoice->invoice_value;
        $beforeAwb = $fulfilment->awb;

        $corrected = $this->corrections->correct(
            $fulfilment,
            'SN-WRONG-801',
            'SN-RIGHT-801',
            $this->actor,
        );

        $invoice->refresh();
        $pdf = app(StatutoryDocumentService::class)->binary($invoice->document);

        $this->assertSame(['SN-RIGHT-801'], $this->workflow->allocatedSerialNumbers($corrected));
        $this->assertSame(['SN-RIGHT-801'], $corrected->metadata['invoice_serials'] ?? []);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-WRONG-801')->firstOrFail()->status);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'SN-RIGHT-801')->firstOrFail()->status);
        $this->assertSame($beforeTotal, (float) $invoice->invoice_value);
        $this->assertSame($beforeAwb, $corrected->awb);
        $this->assertStringContainsString('SN-RIGHT-801', $pdf);
        $this->assertStringNotContainsString('SN-WRONG-801', $pdf);
        $this->assertTrue(InventoryMovement::query()->where('type', InventoryMovementType::SaleCancel)->exists());
        $this->assertTrue(InventoryMovement::query()->where('type', InventoryMovementType::Sale)->where('notes', 'like', '%serial_correction%')->exists());
    }

    public function test_rejects_correction_when_irn_is_submitted(): void
    {
        $fulfilment = $this->issuedFulfilment('RDE900802', 'DELHI-RETAIL', 'SN-WRONG-802', 'SN-RIGHT-802');
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $fulfilment->statutory_invoice_id],
            [
                'provider' => 'none',
                'status' => EInvoiceRecordStatus::Submitted,
            ],
        );

        $this->expectException(ValidationException::class);
        $this->corrections->correct($fulfilment, 'SN-WRONG-802', 'SN-RIGHT-802', $this->actor);
    }

    public function test_rejects_when_correct_serial_is_unavailable_or_allocated_elsewhere(): void
    {
        $fulfilment = $this->issuedFulfilment('RDE900803', 'DELHI-RETAIL', 'SN-WRONG-803', 'SN-RIGHT-803');
        InventorySerial::query()->where('serial_number', 'SN-RIGHT-803')->update([
            'status' => InventorySerialStatus::Sold,
        ]);

        try {
            $this->corrections->correct($fulfilment, 'SN-WRONG-803', 'SN-RIGHT-803', $this->actor);
            $this->fail('Unavailable correct serial must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not available', implode(' ', $exception->errors()['serials'] ?? []));
        }

        InventorySerial::query()->where('serial_number', 'SN-RIGHT-803')->update([
            'status' => InventorySerialStatus::Available,
        ]);
        $other = $this->readyFulfilment('RDE900804', 'DELHI-RETAIL');
        $this->allocation->allocateSerials($other, ['SN-RIGHT-803'], $this->actor);

        try {
            $this->corrections->correct($fulfilment, 'SN-WRONG-803', 'SN-RIGHT-803', $this->actor);
            $this->fail('Serial allocated elsewhere must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not available', implode(' ', $exception->errors()['serials'] ?? []));
        }
    }

    public function test_isolated_allocate_without_serials_fails_closed_and_does_not_auto_pick(): void
    {
        $sourceId = 'RDE900805';
        $this->stockAt('DELHI-RETAIL', ['SN-AUTO-805']);
        $this->ingestHardware($sourceId);
        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
        $this->assignBranch($fulfilment, 'DELHI-RETAIL');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        try {
            $this->artisan('desk:fulfil-hardware', [
                'id' => $sourceId,
                '--step' => 'allocate',
                '--actor' => (string) $this->actor->id,
            ])->assertFailed();
        } catch (ValidationException) {
            // artisan may throw before assertFailed in some harnesses
        }

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->fresh()->state);
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-AUTO-805')->firstOrFail()->status);
    }

    public function test_command_dry_run_previews_without_writing(): void
    {
        $fulfilment = $this->issuedFulfilment('RDE900806', 'DELHI-RETAIL', 'SN-WRONG-806', 'SN-RIGHT-806');

        $this->artisan('desk:correct-hardware-serial', [
            'id' => 'RDE900806',
            '--from' => 'SN-WRONG-806',
            '--to' => 'SN-RIGHT-806',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(['SN-WRONG-806'], $this->workflow->allocatedSerialNumbers($fulfilment->fresh()));
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'SN-WRONG-806')->firstOrFail()->status);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'SN-RIGHT-806')->firstOrFail()->status);
    }

    private function issuedFulfilment(
        string $sourceId,
        string $branchCode,
        string $wrongSerial,
        string $rightSerial,
    ): HardwareFulfilment {
        $fulfilment = $this->readyFulfilment($sourceId, $branchCode);
        $this->stockAt($branchCode, [$wrongSerial, $rightSerial]);
        $this->allocation->allocateSerials($fulfilment, [$wrongSerial], $this->actor);
        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh(), $this->actor);
        $fulfilment = $fulfilment->fresh(['commerceOrder.items', 'serials']);
        $fulfilment->forceFill(['awb' => 'AWB-'.$sourceId])->save();

        return $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
    }

    private function readyFulfilment(string $sourceId, string $branchCode): HardwareFulfilment
    {
        $fulfilment = $this->ingestHardware($sourceId);
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function mapModel(int $modelId): void
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MSO1300',
                'channel_sku' => '951',
                'notes' => 'Test-only Owner map.',
            ],
        );
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): InventoryBranch
    {
        $branch = $this->assignBranch(new HardwareFulfilment, $branchCode);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->actor);

        return $branch;
    }

    private function assignBranch(?HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        if ($fulfilment?->exists) {
            $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
        }

        return $branch;
    }

    private function ingestHardware(string $sourceId): HardwareFulfilment
    {
        $taxable = 2583.90;
        $tax = 465.10;
        $lineTotal = 3049.0;
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
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
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
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body)->assertCreated();

        return HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    }
}
