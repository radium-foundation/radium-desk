<?php

namespace App\Services\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketCancelResult;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCourierOptionsResult;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderResult;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\Data\ShiprocketPickupResult;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\Shipping\Data\ShiprocketTokenResult;
use App\Services\Shipping\Data\ShiprocketTrackResult;

/**
 * Production-safe default. Makes no HTTP or network call and never
 * invents a Shiprocket order, AWB, or tracking scan.
 */
final class NullShiprocketGateway implements ShiprocketGateway
{
    public const MESSAGE = 'Shiprocket integration is disabled. No external order or AWB was created.';

    public function provider(): string
    {
        return 'none';
    }

    public function acquireToken(): ShiprocketTokenResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function createOrder(ShiprocketCreateOrderRequest $request): ShiprocketCreateOrderResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function searchOrders(string $search): ShiprocketSearchResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function listCourierOptions(ShiprocketCourierOptionsRequest $request): ShiprocketCourierOptionsResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function assignAwb(string $externalShipmentId, ?string $courierId = null): ShiprocketAwbResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function requestPickup(string $externalShipmentId): ShiprocketPickupResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function generateLabel(string $externalShipmentId): ShiprocketDocumentResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function generateManifest(string $externalShipmentId): ShiprocketDocumentResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function printInvoice(string $externalShipmentId): ShiprocketDocumentResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function trackByAwb(string $awb): ShiprocketTrackResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function trackByShipment(string $externalShipmentId): ShiprocketTrackResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }

    public function cancelOrders(array $externalOrderIds): ShiprocketCancelResult
    {
        throw new ShiprocketDisabledException(self::MESSAGE);
    }
}
