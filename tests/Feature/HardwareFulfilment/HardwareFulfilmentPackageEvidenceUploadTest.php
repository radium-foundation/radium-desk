<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\ShipmentStatus;
use App\Enums\ShiprocketTrackNormalized;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwareFulfilmentPackageEvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for package photo upload tests.');
        }

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
        Storage::fake('local');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();
    }

    public function test_valid_package_photo_upload_is_stored_within_maximum_size(): void
    {
        $fulfilment = $this->fulfilment('RDE990001');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel->value,
                'photo' => $this->largeJpegUpload(),
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $evidence = HardwareFulfilmentPackageEvidence::query()->firstOrFail();
        $this->assertLessThanOrEqual(150 * 1024, (int) $evidence->size_bytes);
        $this->assertSame('image/jpeg', $evidence->mime_type);
        Storage::disk('local')->assertExists($evidence->path);
        $this->assertNotFalse(@getimagesizefromstring(Storage::disk('local')->get($evidence->path)));
    }

    public function test_missing_package_photo_with_picked_up_tracking_stays_in_needs_action(): void
    {
        $fulfilment = $this->fulfilment('RDE990002', track: ShiprocketTrackNormalized::PickedUp->value);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment->fresh(), $ready);

        $this->assertTrue($ready->packagePhotoEvidenceDue());
        $this->assertTrue($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::NeedsAction));
        $this->assertTrue($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::PackagePhotoPending));
        $this->assertSame('Upload Package Photo', $row->nextAction);

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $page = app(HardwareNeedsActionSqlQuery::class)->page(
            HardwareWorkspaceScope::Active,
            HardwareWorkspaceFilter::NeedsAction,
            $from,
            $to,
            'RDE990002',
            1,
            10,
        );

        $this->assertContains($fulfilment->id, $page['fulfilment_ids']);
    }

    public function test_uploaded_package_photo_clears_package_photo_needs_action(): void
    {
        $fulfilment = $this->fulfilment('RDE990003', track: ShiprocketTrackNormalized::PickedUp->value);

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment), [
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel->value,
                'photo' => UploadedFile::fake()->image('package.jpg', 640, 480),
            ]);

        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment->fresh());
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment->fresh(), $ready);

        $this->assertTrue($ready->packagePhotoRecorded());
        $this->assertFalse($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::PackagePhotoPending));
    }

    private function seedCatalog(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    private function fulfilment(string $sourceId, ?string $track = null): HardwareFulfilment
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer '.$sourceId,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $invoiceModel = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$sourceId,
            'document_type' => 'tax_invoice',
            'status' => 'issued',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'invoice:'.$sourceId,
            'invoice_value' => 2549,
            'issued_at' => now(),
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::AwbAssigned,
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel->id,
            'ingested_at' => now(),
        ]);
        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'line_no' => 1,
            'position' => 1,
            'serial_number' => 'SN-'.$sourceId,
            'status' => HardwareFulfilmentSerialStatus::Allocated,
            'allocated_at' => now(),
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => 'HW-'.$sourceId,
            'commerce_order_id' => $commerce->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::AwbAssigned,
            'external_order_id' => 'ext-'.$sourceId,
            'external_shipment_id' => 'shp-'.$sourceId,
            'awb' => 'AWB-'.$sourceId,
            'label_url' => '/labels/'.$sourceId.'.pdf',
            'pickup_requested_at' => now(),
            'manifest_id' => 'man-'.$sourceId,
            'provider_track_normalized' => $track,
            'provider_track_status' => $track,
            'idempotency_key' => 'ship:'.$sourceId,
            'correlation_id' => (string) Str::uuid(),
        ]);
        $fulfilment->forceFill([
            'shipment_id' => $shipment->id,
            'awb' => 'AWB-'.$sourceId,
        ])->save();

        return $fulfilment->fresh() ?? $fulfilment;
    }

    private function largeJpegUpload(): UploadedFile
    {
        $canvas = imagecreatetruecolor(2200, 1600);
        $background = imagecolorallocate($canvas, 235, 235, 235);
        imagefill($canvas, 0, 0, $background);
        $ink = imagecolorallocate($canvas, 10, 10, 10);
        imagestring($canvas, 5, 40, 40, 'AWB 14112362616794', $ink);

        ob_start();
        imagejpeg($canvas, null, 95);
        $contents = (string) ob_get_clean();
        imagedestroy($canvas);

        $path = tempnam(sys_get_temp_dir(), 'pkg-upload-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'package.jpg', 'image/jpeg', null, true);
    }
}
