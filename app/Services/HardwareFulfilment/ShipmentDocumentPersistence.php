<?php

namespace App\Services\HardwareFulfilment;

use App\Models\Shipment;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ShipmentDocumentPersistence
{
    public static function persistLabel(Shipment $shipment, ShiprocketDocumentResult $result): void
    {
        if ($result->url === null) {
            return;
        }

        self::save($shipment, [
            'label_url' => $result->url,
            'label_fetched_at' => now(),
            'last_error' => null,
        ], 'label', $result);
    }

    public static function persistManifest(Shipment $shipment, ShiprocketDocumentResult $result): void
    {
        if ($result->url === null && $result->documentId === null) {
            return;
        }

        self::save($shipment, [
            'manifest_url' => $result->url,
            'manifest_id' => $result->documentId ?? $shipment->manifest_id,
            'manifest_generated_at' => now(),
            'last_error' => null,
        ], 'manifest', $result);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function save(
        Shipment $shipment,
        array $attributes,
        string $document,
        ShiprocketDocumentResult $result,
    ): void {
        try {
            $shipment->forceFill($attributes)->save();
        } catch (QueryException $exception) {
            Log::warning('hardware.shipment_document_persist_failed', [
                'shipment_id' => $shipment->id,
                'external_shipment_id' => $shipment->external_shipment_id,
                'document' => $document,
                'provider' => $result->provider,
                'manifest_id' => $result->documentId,
                'url_length' => $result->url !== null ? strlen($result->url) : null,
                'sql_state' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]);

            throw ValidationException::withMessages([
                'shipping' => self::persistenceFailureMessage($document),
            ]);
        }
    }

    private static function persistenceFailureMessage(string $document): string
    {
        return 'Shiprocket may have created the '.$document
            .', but Desk could not save the document reference locally. '
            .'Do not retry Generate blindly — check Shiprocket and contact support with the shipment id. '
            .'A database migration may be required on this environment.';
    }
}
