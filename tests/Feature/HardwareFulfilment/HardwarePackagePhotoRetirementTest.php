<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\Shipment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardwarePackagePhotoRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_user_mark_shipped_succeeds_without_package_evidence(): void
    {
        $fulfilment = $this->awbFulfilment('RDE991001');

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $updated = app(HardwareFulfilmentWorkflowService::class)->markShipped($fulfilment->fresh(['shipment']), 'user', $actor->id);

        $this->assertSame(HardwareFulfilmentState::Shipped, $updated->state);
        $this->assertSame(0, HardwareFulfilmentPackageEvidence::query()->count());
    }

    public function test_historical_package_evidence_show_remains_readable(): void
    {
        Storage::fake('local');
        $path = 'private/hardware-fulfilment-evidence/historical.jpg';
        Storage::disk('local')->put($path, 'jpeg-bytes');

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE991002',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE991002',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE991002',
            'payload_hash' => hash('sha256', 'RDE991002'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE991002',
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::AwbAssigned,
            'ingested_at' => now(),
        ]);

        $evidence = HardwareFulfilmentPackageEvidence::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'kind' => HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'historical.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 9,
            'uploaded_by_user_id' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('inventory.hardware-fulfilments.package-evidence.show', [$fulfilment, $evidence]))
            ->assertOk();
    }

    private function awbFulfilment(string $sourceId): HardwareFulfilment
    {
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
            'received_at' => now(),
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::AwbAssigned,
            'awb' => 'AWB-'.$sourceId,
            'provider_awb' => 'AWB-'.$sourceId,
            'ingested_at' => now(),
        ]);

        Shipment::query()->create([
            'commerce_order_id' => $commerce->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'shipment_no' => 'SHP-'.$sourceId,
            'idempotency_key' => 'shipping:shiprocket:'.$sourceId,
            'correlation_id' => (string) Str::uuid(),
            'provider' => 'shiprocket',
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => 'AWB-'.$sourceId,
        ]);

        return $fulfilment->fresh(['shipment']);
    }
}
