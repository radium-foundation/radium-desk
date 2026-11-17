<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\HardwareFulfilment\CarrierDocumentUrlValidator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;
use ZipArchive;

class HardwareFulfilmentBulkDownloadTest extends TestCase
{
    use RefreshDatabase;

    private FakeShiprocketGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();

        $this->fake = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->fake);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_bulk_label_download_returns_zip_without_calling_generate_batch(): void
    {
        $admin = $this->adminUser();
        [$fulfilmentA, $shipmentA] = $this->fulfilmentWithDocuments('RDE910001', 'AWB910001', 'MAN910001');
        [$fulfilmentB, $shipmentB] = $this->fulfilmentWithDocuments('RDE910002', 'AWB910002', 'MAN910002');

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF-label%', 200, ['Content-Type' => 'application/pdf']),
        ]);
        config(['shipping.document_download_allowed_hosts' => ['apiv2.shiprocket.in']]);

        $eventsBefore = ShipmentEvent::query()->count();
        $fulfilmentEventsBefore = HardwareFulfilmentEvent::query()->count();

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilmentA->id, $fulfilmentB->id, $fulfilmentA->id],
            ]);

        $response->assertOk();
        $this->assertSame(0, $this->fake->labelBatchCalls);
        $this->assertSame(0, $this->fake->manifestBatchCalls);
        $this->assertSame($eventsBefore, ShipmentEvent::query()->count());
        $this->assertSame($fulfilmentEventsBefore, HardwareFulfilmentEvent::query()->count());

        $zipBytes = $response->streamedContent();
        $path = storage_path('app/temp-test-'.Str::uuid().'.zip');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(2, $this->countZipEntriesMatching($zip, '/^labels\//'));
        $zip->close();
        @unlink($path);

        $summary = json_decode(base64_decode((string) $response->headers->get('X-Hardware-Bulk-Download-Summary')), true);
        $this->assertSame(2, $summary['selected']);
        $this->assertSame(2, $summary['included']);
    }

    public function test_bulk_manifest_download_dedupes_identical_manifest_id(): void
    {
        $admin = $this->adminUser();
        [$fulfilmentA, $shipmentA] = $this->fulfilmentWithDocuments('RDE910011', 'AWB910011', 'SHARED-MAN');
        [$fulfilmentB, $shipmentB] = $this->fulfilmentWithDocuments('RDE910012', 'AWB910012', 'SHARED-MAN');

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF-manifest%', 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.manifests'), [
                'fulfilment_ids' => [$fulfilmentA->id, $fulfilmentB->id],
            ]);

        $response->assertOk();
        $zipBytes = $response->streamedContent();
        $path = storage_path('app/temp-test-'.Str::uuid().'.zip');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(1, $this->countZipEntriesMatching($zip, '/^manifests\//'));
        $zip->close();
        @unlink($path);
    }

    public function test_package_download_includes_reconciliation_csv(): void
    {
        $admin = $this->adminUser();
        [$fulfilment, $shipment] = $this->fulfilmentWithDocuments('RDE910021', 'AWB910021', 'MAN910021');

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF%', 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.package'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertOk();
        $zipBytes = $response->streamedContent();
        $path = storage_path('app/temp-test-'.Str::uuid().'.zip');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertNotFalse($zip->locateName('reconciliation.csv'));
        $csv = $zip->getFromName('reconciliation.csv');
        $this->assertIsString($csv);
        $this->assertStringContainsString('fulfilment_id', $csv);
        $zip->close();
        @unlink($path);
    }

    public function test_malicious_label_url_is_not_fetched(): void
    {
        $admin = $this->adminUser();
        [$fulfilment, $shipment] = $this->fulfilmentWithDocuments(
            'RDE910031',
            'AWB910031',
            'MAN910031',
            labelUrl: 'http://127.0.0.1/secret.pdf',
        );

        $response = $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('failed', 1);
        Http::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_bulk_download(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);
        [$fulfilment] = $this->fulfilmentWithDocuments('RDE910041', 'AWB910041', 'MAN910041');

        $this->actingAs($stranger)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertForbidden();
    }

    public function test_dashboard_exposes_bulk_download_controls_and_fulfilment_checkbox_ids(): void
    {
        $admin = $this->adminUser();
        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hardware-download-labels', $html);
        $this->assertStringContainsString('data-hardware-download-package', $html);
        $this->assertStringContainsString('hardware-fulfilments/bulk/download/labels', $html);
    }

    public function test_url_validator_blocks_private_hosts(): void
    {
        $validator = CarrierDocumentUrlValidator::fromConfig();
        $this->assertFalse($validator->isAllowedUrl('http://127.0.0.1/a.pdf'));
        $this->assertFalse($validator->isAllowedUrl('https://evil.test/a.pdf'));
        $this->assertTrue($validator->isAllowedUrl('https://apiv2.shiprocket.in/v1/labels/1.pdf'));
    }

    public function test_external_shipment_local_label_is_included_without_http(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('external/AWBEXT001.pdf', '%PDF-external%');

        $admin = $this->adminUser();
        [$fulfilment] = $this->fulfilmentWithDocuments(
            'RDE910051',
            'AWBEXT001',
            'MANEXT001',
            labelUrl: null,
            manifestUrl: null,
        );
        $fulfilment->shipment?->forceFill([
            'provider' => 'external',
            'label_disk' => 'local',
            'label_path' => 'external/AWBEXT001.pdf',
            'label_url' => null,
        ])->save();

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->awbs);
    }

    public function test_operator_in_wrong_branch_is_excluded(): void
    {
        $mumbai = InventoryBranch::query()->firstOrCreate(
            ['code' => 'MUMBAI'],
            ['name' => 'Mumbai', 'is_active' => true],
        );
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $operator->id,
            'branch_id' => $mumbai->id,
        ]);

        $delhi = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'Delhi', 'is_active' => true],
        );

        [$fulfilment] = $this->fulfilmentWithDocuments('RDE910061', 'AWB910061', 'MAN910061');
        $fulfilment->forceFill(['fulfilment_branch_id' => $delhi->id])->save();

        $response = $this->actingAs($operator)
            ->postJson(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('excluded', 1);
        $response->assertJsonPath('rows.0.exclusion_reason', 'Not authorized for this fulfilment branch.');
    }

    public function test_distinct_manifest_identities_produce_separate_manifest_files(): void
    {
        $admin = $this->adminUser();
        [$fulfilmentA] = $this->fulfilmentWithDocuments('RDE910071', 'AWB910071', 'MAN-A');
        [$fulfilmentB] = $this->fulfilmentWithDocuments('RDE910072', 'AWB910072', 'MAN-B');

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF-manifest%', 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.manifests'), [
                'fulfilment_ids' => [$fulfilmentA->id, $fulfilmentB->id],
            ]);

        $response->assertOk();
        $zipBytes = $response->streamedContent();
        $path = storage_path('app/temp-test-'.Str::uuid().'.zip');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(2, $this->countZipEntriesMatching($zip, '/^manifests\//'));
        $zip->close();
        @unlink($path);
    }

    public function test_missing_label_and_manifest_surfaces_failed_rows(): void
    {
        $admin = $this->adminUser();
        [$fulfilment] = $this->fulfilmentWithDocuments(
            'RDE910081',
            'AWB910081',
            'MAN910081',
            labelUrl: null,
            manifestUrl: null,
        );
        $fulfilment->shipment?->forceFill([
            'label_url' => null,
            'manifest_url' => null,
            'manifest_id' => null,
            'label_disk' => null,
            'label_path' => null,
        ])->save();

        $response = $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('failed', 1);
    }

    public function test_partial_package_includes_available_documents_and_csv(): void
    {
        $admin = $this->adminUser();
        [$withLabel] = $this->fulfilmentWithDocuments('RDE910091', 'AWB910091', 'MAN910091');
        [$without] = $this->fulfilmentWithDocuments(
            'RDE910092',
            'AWB910092',
            'MAN910092',
            labelUrl: null,
            manifestUrl: null,
        );
        $without->shipment?->forceFill([
            'label_url' => null,
            'manifest_url' => null,
            'manifest_id' => null,
        ])->save();

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF%', 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.package'), [
                'fulfilment_ids' => [$withLabel->id, $without->id],
            ]);

        $response->assertOk();
        $summary = json_decode(base64_decode((string) $response->headers->get('X-Hardware-Bulk-Download-Summary')), true);
        $this->assertSame(1, $summary['included']);
        $this->assertGreaterThanOrEqual(1, $summary['failed']);
    }

    public function test_repeated_downloads_do_not_mutate_shipment_rows(): void
    {
        $admin = $this->adminUser();
        [$fulfilment, $shipment] = $this->fulfilmentWithDocuments('RDE910101', 'AWB910101', 'MAN910101');
        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF%', 200),
        ]);

        $hashBefore = $shipment->only(['label_url', 'manifest_url', 'awb', 'status']);

        $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertOk();

        $shipment->refresh();
        $this->assertSame($hashBefore, $shipment->only(['label_url', 'manifest_url', 'awb', 'status']));
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->awbs);
        $this->assertSame(0, $this->fake->labels);
        $this->assertSame(0, $this->fake->manifests);
    }

    public function test_zip_entry_names_sanitize_path_traversal_attempts(): void
    {
        $admin = $this->adminUser();
        $orderId = '../evil/RDE910111';
        [$fulfilment] = $this->fulfilmentWithDocuments($orderId, 'AWB910111', 'MAN910111');

        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF%', 200),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [$fulfilment->id],
            ]);

        $response->assertOk();
        $zipBytes = $response->streamedContent();
        $path = storage_path('app/temp-test-'.Str::uuid().'.zip');
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive;
        $zip->open($path);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertStringStartsWith('labels/', $name);
            $this->assertDoesNotMatchRegularExpression('#(^|/)\.\.(/|$)#', $name);
        }
        $zip->close();
        @unlink($path);
    }

    public function test_unknown_fulfilment_id_is_excluded_without_archive(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson(route('inventory.hardware-fulfilments.bulk.download.labels'), [
                'fulfilment_ids' => [999999],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('excluded', 1);
    }

    public function test_gateway_generation_endpoints_remain_unused(): void
    {
        $admin = $this->adminUser();
        [$fulfilment] = $this->fulfilmentWithDocuments('RDE910121', 'AWB910121', 'MAN910121');
        Http::fake([
            'https://apiv2.shiprocket.in/*' => Http::response('%PDF%', 200),
        ]);

        $this->actingAs($admin)
            ->post(route('inventory.hardware-fulfilments.bulk.download.package'), [
                'fulfilment_ids' => [$fulfilment->id],
            ])
            ->assertOk();

        $this->assertSame(0, $this->fake->labelBatchCalls);
        $this->assertSame(0, $this->fake->manifestBatchCalls);
        $this->assertSame(0, $this->fake->creates);
        $this->assertSame(0, $this->fake->awbs);
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'Delhi', 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        return $user;
    }

    /**
     * @return array{0: HardwareFulfilment, 1: Shipment}
     */
    private function fulfilmentWithDocuments(
        string $orderId,
        string $awb,
        string $manifestId,
        ?string $labelUrl = null,
        ?string $manifestUrl = null,
    ): array {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$orderId,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => 'DELHI-RETAIL'],
            ['name' => 'Delhi', 'is_active' => true],
        );

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::AwbAssigned,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $branch->id,
            'ingested_at' => now(),
        ]);

        $labelUrl ??= 'https://apiv2.shiprocket.in/v1/labels/'.$orderId.'.pdf';
        $manifestUrl ??= 'https://apiv2.shiprocket.in/v1/manifests/'.$orderId.'.pdf';

        $shipment = Shipment::query()->create([
            'shipment_no' => 'HW-'.$orderId,
            'commerce_order_id' => $commerce->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::AwbAssigned,
            'external_order_id' => 'ext-'.$orderId,
            'external_shipment_id' => 'shp-'.$orderId,
            'awb' => $awb,
            'label_url' => $labelUrl,
            'manifest_url' => $manifestUrl,
            'manifest_id' => $manifestId,
            'idempotency_key' => 'ship:'.$orderId,
            'correlation_id' => (string) Str::uuid(),
        ]);

        $fulfilment->forceFill([
            'shipment_id' => $shipment->id,
            'awb' => $awb,
        ])->save();

        return [$fulfilment->fresh(['shipment']) ?? $fulfilment, $shipment];
    }

    private function countZipEntriesMatching(ZipArchive $zip, string $pattern): int
    {
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
