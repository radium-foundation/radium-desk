<?php

namespace Tests\Feature\Shipping\Support;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketCancelResult;
use App\Services\Shipping\Data\ShiprocketCourierOption;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCourierOptionsResult;
use App\Services\Shipping\Data\ShiprocketCreateOrderRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderResult;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\Data\ShiprocketPickupResult;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\Shipping\Data\ShiprocketTokenResult;
use App\Services\Shipping\Data\ShiprocketTrackResult;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use RuntimeException;

/**
 * In-memory Shiprocket adapter for tests. No HTTP.
 *
 * Modes:
 * - accepted: store catalog + return deterministic IDs
 * - rejected: no catalog
 * - retryable: no catalog, retryable result
 * - timeout: throw without catalog
 * - timeout_accepted: store catalog then throw (lost response after accept)
 */
final class FakeShiprocketGateway implements ShiprocketGateway
{
    public int $tokens = 0;

    public int $creates = 0;

    public int $searches = 0;

    public int $awbs = 0;

    public int $pickups = 0;

    public int $labels = 0;

    public int $manifests = 0;

    public int $invoices = 0;

    public int $tracks = 0;

    public int $cancels = 0;

    public int $courierLists = 0;

    public ?ShiprocketCourierOptionsRequest $lastCourierRequest = null;

    public ?ShiprocketCreateOrderRequest $lastCreateRequest = null;

    public string $mode = 'accepted';

    public ?string $nextCourierListMode = null;

    public ?string $nextLabelMode = null;

    public ?string $nextManifestMode = null;

    public ?string $recommendedCourierId = null;

    /**
     * @var list<array{courier_id: string, courier_name: string, rate?: string, coverage_charge?: string, estimated_delivery?: string, cod_available?: bool, prepaid_available?: bool}>
     */
    public array $courierOptions = [
        [
            'courier_id' => '12',
            'courier_name' => 'Fake Surface',
            'rate' => '85.5',
            'coverage_charge' => '0',
            'estimated_delivery' => '3 days',
            'cod_available' => true,
            'prepaid_available' => true,
            'courier_type' => 'Surface',
        ],
        [
            'courier_id' => '44',
            'courier_name' => 'Fake Express',
            'rate' => '120',
            'prepaid_available' => true,
        ],
    ];

    public ?string $nextCreateMode = null;

    public ?string $nextSearchMode = null;

    public ?string $nextAssignMode = null;

    public ?string $nextPickupMode = null;

    /**
     * @var array<string, array{external_order_id: string, external_shipment_id: string}>
     */
    public array $catalog = [];

    /**
     * @var array<string, array{awb: string, courier_id: string, courier_name: string}>
     */
    public array $awbCatalog = [];

    /**
     * @var array<string, true>
     */
    public array $pickupCatalog = [];

    /**
     * @var list<string>
     */
    public array $createdMerchantOrderIds = [];

    public function provider(): string
    {
        return 'test';
    }

    public function acquireToken(): ShiprocketTokenResult
    {
        $this->tokens++;
        $this->assertSuccessMode();

        return new ShiprocketTokenResult(token: 'fake-token', ttlSeconds: 3600);
    }

    public function createOrder(ShiprocketCreateOrderRequest $request): ShiprocketCreateOrderResult
    {
        $this->creates++;
        $this->lastCreateRequest = $request;
        $this->createdMerchantOrderIds[] = $request->merchantOrderId;

        $mode = $this->nextCreateMode ?? $this->mode;
        $this->nextCreateMode = null;

        return match ($mode) {
            'accepted' => $this->accept($request),
            'rejected' => new ShiprocketCreateOrderResult(
                provider: $this->provider(),
                status: 'rejected',
                correlationId: $request->correlationId,
                error: 'Fake provider rejected the create-order request.',
                retryable: false,
            ),
            'retryable' => new ShiprocketCreateOrderResult(
                provider: $this->provider(),
                status: 'failed',
                correlationId: $request->correlationId,
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider timeout.'),
            'timeout_accepted' => $this->timeoutAfterAccept($request),
            'auth_failed' => throw new ShiprocketNonRetryableException('Shiprocket authentication failed.'),
            default => throw new RuntimeException('Unknown fake Shiprocket mode: '.$mode),
        };
    }

    public function searchOrders(string $search): ShiprocketSearchResult
    {
        $this->searches++;

        $mode = $this->nextSearchMode;
        $this->nextSearchMode = null;
        if ($mode === 'timeout' || $mode === 'retryable') {
            return new ShiprocketSearchResult(
                provider: $this->provider(),
                found: false,
                merchantOrderId: $search,
                error: 'Fake provider search timed out.',
                retryable: true,
            );
        }

        $row = $this->catalog[$search] ?? $this->catalogRowForAwb($search);
        if ($row === null) {
            return new ShiprocketSearchResult(
                provider: $this->provider(),
                found: false,
                merchantOrderId: $search,
            );
        }

        $awb = $this->awbCatalog[$row['external_shipment_id']] ?? null;

        return new ShiprocketSearchResult(
            provider: $this->provider(),
            found: true,
            externalOrderId: $row['external_order_id'],
            externalShipmentId: $row['external_shipment_id'],
            merchantOrderId: $search,
            status: 'NEW',
            awb: $awb['awb'] ?? null,
            courierId: $awb['courier_id'] ?? null,
            courierName: $awb['courier_name'] ?? null,
        );
    }

    public function seedCatalog(string $merchantOrderId, string $externalOrderId, string $externalShipmentId): void
    {
        $this->catalog[$merchantOrderId] = [
            'external_order_id' => $externalOrderId,
            'external_shipment_id' => $externalShipmentId,
        ];
    }

    public function seedAwb(string $externalShipmentId, string $awb, string $courierId = '12', string $courierName = 'Fake Courier'): void
    {
        $this->awbCatalog[$externalShipmentId] = [
            'awb' => $awb,
            'courier_id' => $courierId,
            'courier_name' => $courierName,
        ];
    }

    public function seedPickup(string $externalShipmentId): void
    {
        $this->pickupCatalog[$externalShipmentId] = true;
    }

    public function listCourierOptions(ShiprocketCourierOptionsRequest $request): ShiprocketCourierOptionsResult
    {
        $this->courierLists++;
        $this->lastCourierRequest = $request;

        $mode = $this->nextCourierListMode ?? $this->mode;
        $this->nextCourierListMode = null;

        return match ($mode) {
            'accepted' => $this->acceptCourierOptions(),
            'rejected' => new ShiprocketCourierOptionsResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'Fake provider rejected courier options.',
                retryable: false,
            ),
            'retryable' => new ShiprocketCourierOptionsResult(
                provider: $this->provider(),
                status: 'failed',
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider courier-options timeout.'),
            default => throw new RuntimeException('Unknown fake Shiprocket courier-list mode: '.$mode),
        };
    }

    public function assignAwb(string $externalShipmentId, ?string $courierId = null): ShiprocketAwbResult
    {
        $this->awbs++;
        $mode = $this->nextAssignMode ?? $this->mode;
        $this->nextAssignMode = null;

        return match ($mode) {
            'accepted' => $this->acceptAwb($externalShipmentId, $courierId),
            'rejected' => new ShiprocketAwbResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'Fake provider rejected AWB assignment.',
                retryable: false,
            ),
            'retryable' => new ShiprocketAwbResult(
                provider: $this->provider(),
                status: 'failed',
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider timeout.'),
            'timeout_accepted' => $this->timeoutAfterAwbAccept($externalShipmentId, $courierId),
            default => throw new RuntimeException('Unknown fake Shiprocket mode: '.$mode),
        };
    }

    public function requestPickup(string $externalShipmentId): ShiprocketPickupResult
    {
        $this->pickups++;
        $mode = $this->nextPickupMode ?? $this->mode;
        $this->nextPickupMode = null;

        return match ($mode) {
            'accepted' => $this->acceptPickup($externalShipmentId),
            'already_queued' => new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'already_requested',
                error: 'HTTP 400 — Already in Pickup Queue',
                alreadyQueued: true,
            ),
            'already_queued_rejected' => new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'HTTP 400 — Already in Pickup Queue.',
                alreadyQueued: false,
            ),
            'rejected' => new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'Fake provider rejected pickup generation.',
                retryable: false,
            ),
            'retryable' => new ShiprocketPickupResult(
                provider: $this->provider(),
                status: 'failed',
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider pickup timeout.'),
            'timeout_accepted' => $this->timeoutAfterPickupAccept($externalShipmentId),
            default => throw new RuntimeException('Unknown fake Shiprocket mode: '.$mode),
        };
    }

    public function generateLabel(string $externalShipmentId): ShiprocketDocumentResult
    {
        $this->labels++;
        $mode = $this->nextLabelMode ?? $this->mode;
        $this->nextLabelMode = null;

        return match ($mode) {
            'accepted' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'generated',
                url: 'https://fake.local/labels/'.$externalShipmentId,
            ),
            'rejected' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'Fake provider rejected label generation.',
                retryable: false,
            ),
            'retryable' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'failed',
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider label timeout.'),
            default => throw new RuntimeException('Unknown fake Shiprocket label mode: '.$mode),
        };
    }

    public function generateManifest(string $externalShipmentId): ShiprocketDocumentResult
    {
        $this->manifests++;
        $mode = $this->nextManifestMode ?? $this->mode;
        $this->nextManifestMode = null;

        return match ($mode) {
            'accepted' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'generated',
                url: 'https://fake.local/manifests/'.$externalShipmentId,
                documentId: 'MF-'.$externalShipmentId,
            ),
            'rejected' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'rejected',
                error: 'Fake provider rejected manifest generation.',
                retryable: false,
            ),
            'retryable' => new ShiprocketDocumentResult(
                provider: $this->provider(),
                status: 'failed',
                error: 'Fake provider unavailable.',
                retryable: true,
            ),
            'timeout' => throw new ShiprocketRetryableException('Fake provider manifest timeout.'),
            default => throw new RuntimeException('Unknown fake Shiprocket manifest mode: '.$mode),
        };
    }

    public function printInvoice(string $externalShipmentId): ShiprocketDocumentResult
    {
        $this->invoices++;
        $this->assertSuccessMode();

        return new ShiprocketDocumentResult(
            provider: $this->provider(),
            status: 'generated',
            url: 'https://fake.local/invoices/'.$externalShipmentId,
        );
    }

    public function trackByAwb(string $awb): ShiprocketTrackResult
    {
        $this->tracks++;
        $this->assertSuccessMode();
        $row = $this->awbRowByAwb($awb);

        return new ShiprocketTrackResult(
            provider: $this->provider(),
            status: 'in_transit',
            activities: [
                [
                    'awb' => $awb,
                    'activity' => 'Picked up',
                    'location' => 'Delhi',
                ],
            ],
            awb: $awb,
            courierId: $row['courier_id'] ?? '12',
            courierName: $row['courier_name'] ?? 'Fake Courier',
        );
    }

    public function trackByShipment(string $externalShipmentId): ShiprocketTrackResult
    {
        $this->tracks++;
        $row = $this->awbCatalog[$externalShipmentId] ?? null;
        if ($row === null) {
            return new ShiprocketTrackResult(
                provider: $this->provider(),
                status: 'unknown',
                externalShipmentId: $externalShipmentId,
            );
        }

        return new ShiprocketTrackResult(
            provider: $this->provider(),
            status: isset($this->pickupCatalog[$externalShipmentId]) ? 'pickup_requested' : 'awb_assigned',
            activities: [],
            awb: $row['awb'],
            courierId: $row['courier_id'],
            courierName: $row['courier_name'],
            externalShipmentId: $externalShipmentId,
            pickupScheduledAt: isset($this->pickupCatalog[$externalShipmentId])
                ? '2026-09-03 09:00'
                : null,
        );
    }

    public function cancelOrders(array $externalOrderIds): ShiprocketCancelResult
    {
        $this->cancels++;
        $this->assertSuccessMode();

        return new ShiprocketCancelResult(
            provider: $this->provider(),
            status: 'cancelled',
        );
    }

    private function acceptCourierOptions(): ShiprocketCourierOptionsResult
    {
        $recommended = $this->recommendedCourierId !== null
            ? trim($this->recommendedCourierId)
            : null;
        if ($recommended === '') {
            $recommended = null;
        }

        $options = [];
        foreach ($this->courierOptions as $row) {
            $id = (string) $row['courier_id'];
            $options[] = new ShiprocketCourierOption(
                courierId: $id,
                courierName: $row['courier_name'] ?? null,
                rate: $row['rate'] ?? null,
                coverageCharge: $row['coverage_charge'] ?? null,
                estimatedDelivery: $row['estimated_delivery'] ?? null,
                codAvailable: $row['cod_available'] ?? null,
                prepaidAvailable: $row['prepaid_available'] ?? null,
                providerRecommended: $recommended !== null && $recommended === $id,
                courierType: $row['courier_type'] ?? null,
                mode: $row['mode'] ?? null,
            );
        }

        return new ShiprocketCourierOptionsResult(
            provider: $this->provider(),
            status: 'listed',
            options: $options,
            recommendedCourierId: $recommended,
            recommendationReturned: $recommended !== null,
        );
    }

    private function accept(ShiprocketCreateOrderRequest $request): ShiprocketCreateOrderResult
    {
        $ids = $this->idsFor($request);
        $this->catalog[$request->merchantOrderId] = $ids;

        return new ShiprocketCreateOrderResult(
            provider: $this->provider(),
            status: 'created',
            externalOrderId: $ids['external_order_id'],
            externalShipmentId: $ids['external_shipment_id'],
            correlationId: $request->correlationId,
        );
    }

    private function timeoutAfterAccept(ShiprocketCreateOrderRequest $request): never
    {
        $this->catalog[$request->merchantOrderId] = $this->idsFor($request);

        throw new ShiprocketRetryableException('Fake provider timeout after accept.');
    }

    private function acceptAwb(string $externalShipmentId, ?string $courierId): ShiprocketAwbResult
    {
        $row = $this->storeAwb($externalShipmentId, $courierId);

        return new ShiprocketAwbResult(
            provider: $this->provider(),
            status: 'assigned',
            awb: $row['awb'],
            courierId: $row['courier_id'],
            courierName: $row['courier_name'],
        );
    }

    private function timeoutAfterAwbAccept(string $externalShipmentId, ?string $courierId): never
    {
        $this->storeAwb($externalShipmentId, $courierId);

        throw new ShiprocketRetryableException('Fake provider timeout after AWB accept.');
    }

    private function acceptPickup(string $externalShipmentId): ShiprocketPickupResult
    {
        $this->pickupCatalog[$externalShipmentId] = true;

        return new ShiprocketPickupResult(
            provider: $this->provider(),
            status: 'requested',
        );
    }

    private function timeoutAfterPickupAccept(string $externalShipmentId): never
    {
        $this->pickupCatalog[$externalShipmentId] = true;

        throw new ShiprocketRetryableException('Fake provider timeout after pickup accept.');
    }

    /**
     * @return array{awb: string, courier_id: string, courier_name: string}
     */
    private function storeAwb(string $externalShipmentId, ?string $courierId): array
    {
        if (isset($this->awbCatalog[$externalShipmentId])) {
            return $this->awbCatalog[$externalShipmentId];
        }

        $row = [
            'awb' => 'AWB-'.$externalShipmentId,
            'courier_id' => $courierId ?? '12',
            'courier_name' => 'Fake Courier',
        ];
        $this->awbCatalog[$externalShipmentId] = $row;

        return $row;
    }

    /**
     * @return array{external_order_id: string, external_shipment_id: string}|null
     */
    private function catalogRowForAwb(string $awb): ?array
    {
        foreach ($this->awbCatalog as $externalShipmentId => $awbRow) {
            if ($awbRow['awb'] !== $awb) {
                continue;
            }
            foreach ($this->catalog as $merchantOrderId => $row) {
                if ($row['external_shipment_id'] === $externalShipmentId) {
                    return $row + ['merchant_order_id' => $merchantOrderId];
                }
            }
        }

        return null;
    }

    /**
     * @return array{awb: string, courier_id: string, courier_name: string}|null
     */
    private function awbRowByAwb(string $awb): ?array
    {
        foreach ($this->awbCatalog as $row) {
            if ($row['awb'] === $awb) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{external_order_id: string, external_shipment_id: string}
     */
    private function idsFor(ShiprocketCreateOrderRequest $request): array
    {
        if (isset($this->catalog[$request->merchantOrderId])) {
            return $this->catalog[$request->merchantOrderId];
        }

        return [
            'external_order_id' => 'SR-ORD-'.$request->localShipmentId,
            'external_shipment_id' => 'SR-SHP-'.$request->localShipmentId,
        ];
    }

    private function assertSuccessMode(): void
    {
        match ($this->mode) {
            'accepted', 'timeout_accepted' => null,
            'rejected' => throw new RuntimeException('Fake provider rejected the request.'),
            'retryable', 'timeout' => throw new ShiprocketRetryableException('Fake provider unavailable.'),
            default => throw new RuntimeException('Unknown fake Shiprocket mode: '.$this->mode),
        };
    }
}
