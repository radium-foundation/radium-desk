<?php

namespace App\Services\HardwareFulfilment;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Archives provider-generated carrier PDFs (manifest/label URLs) into private local storage.
 */
final class HardwareCarrierProviderDocumentArchiver
{
    public function __construct(
        private readonly HardwareCarrierDocumentFetcher $fetcher,
    ) {}

    public static function fromConfig(): self
    {
        return new self(HardwareCarrierDocumentFetcher::fromConfig());
    }

    public function archiveManifestPdf(Shipment $shipment, string $sourceUrl, ?string $manifestId): Shipment
    {
        if (filled($shipment->manifest_path) && filled($shipment->manifest_disk)) {
            return $shipment;
        }

        $url = trim($sourceUrl);
        if ($url === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket returned a manifest without a downloadable PDF URL.',
            ]);
        }

        $remote = $this->fetcher->fetchRemotePdf($url);
        if ($remote === null || $remote['bytes'] === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket manifest PDF could not be retrieved from the provider URL. Do not retry Generate Manifest blindly — reconcile in Shiprocket first.',
            ]);
        }

        if (! str_starts_with($remote['bytes'], '%PDF')) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket manifest download did not return a valid PDF.',
            ]);
        }

        $slug = Str::slug($manifestId ?: ('shipment-'.$shipment->external_shipment_id), '-');
        $path = 'private/hardware-provider-shipment-documents/'.$shipment->id.'/manifest-'.$slug.'.pdf';

        if (! Storage::disk('local')->put($path, $remote['bytes'])) {
            throw ValidationException::withMessages([
                'shipping' => 'Desk could not store the Shiprocket manifest PDF locally.',
            ]);
        }

        $sha256 = hash('sha256', $remote['bytes']);
        $previousPath = $shipment->manifest_path;
        $previousDisk = $shipment->manifest_disk ?: 'local';

        $shipment->forceFill([
            'manifest_disk' => 'local',
            'manifest_path' => $path,
        ])->save();

        if (filled($previousPath) && $previousPath !== $path) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => 'manifest_archived',
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'manifest_id' => $manifestId,
                'source_url_host' => parse_url($url, PHP_URL_HOST),
                'byte_length' => strlen($remote['bytes']),
                'sha256' => $sha256,
                'disk' => 'local',
                'path' => $path,
            ],
        ]);

        return $shipment->fresh() ?? $shipment;
    }
}