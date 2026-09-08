<?php

namespace App\Contracts\Shipping;

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
 * Desk-owned Shiprocket adapter. Domain code must not call HTTP.
 * Production binds NullShiprocketGateway until a real HTTP client exists.
 */
interface ShiprocketGateway
{
    public function provider(): string;

    public function acquireToken(): ShiprocketTokenResult;

    public function createOrder(ShiprocketCreateOrderRequest $request): ShiprocketCreateOrderResult;

    public function searchOrders(string $search): ShiprocketSearchResult;

    public function listCourierOptions(ShiprocketCourierOptionsRequest $request): ShiprocketCourierOptionsResult;

    public function assignAwb(string $externalShipmentId, ?string $courierId = null): ShiprocketAwbResult;

    public function requestPickup(string $externalShipmentId): ShiprocketPickupResult;

    public function generateLabel(string $externalShipmentId): ShiprocketDocumentResult;

    public function generateManifest(string $externalShipmentId): ShiprocketDocumentResult;

    public function printInvoice(string $externalShipmentId): ShiprocketDocumentResult;

    public function trackByAwb(string $awb): ShiprocketTrackResult;

    public function trackByShipment(string $externalShipmentId): ShiprocketTrackResult;

    /**
     * @param  list<int|string>  $externalOrderIds
     */
    public function cancelOrders(array $externalOrderIds): ShiprocketCancelResult;
}
