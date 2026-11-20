<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class HardwareFulfilmentProviderManifestArchiveTest extends HardwareFulfilmentOperationalWorkflowTest
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_provider_manifest_pdf_is_archived_and_served_from_private_storage(): void
    {
        config([
            'shipping.document_download_allowed_hosts' => array_values(array_unique(array_merge(
                config('shipping.document_download_allowed_hosts', []),
                ['fake.local'],
            ))),
        ]);

        $fulfilment = $this->pickupReadyFulfilment('RDE990053');
        $manifestUrl = 'https://fake.local/manifests/RDE990053.pdf';
        $pdf = '%PDF-1.4 shiprocket manifest bytes';

        Http::fake([
            $manifestUrl => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->fake->nextManifestUrl = $manifestUrl;

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', 'Manifest generated.');

        $shipment = Shipment::query()->firstOrFail();
        $this->assertNotNull($shipment->manifest_id);
        $this->assertTrue($shipment->hasUploadedManifest());
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get((string) $shipment->manifest_path));
        $this->assertTrue(ShipmentEvent::query()->where('activity', 'manifest_archived')->exists());

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.manifest.download', $fulfilment->fresh()))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.manifest.view', $fulfilment->fresh()))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($this->admin)
            ->post(route('inventory.hardware-fulfilments.manifest.store', $fulfilment->fresh()))
            ->assertRedirect();

        $this->assertSame(1, $this->fake->manifests);
    }
}