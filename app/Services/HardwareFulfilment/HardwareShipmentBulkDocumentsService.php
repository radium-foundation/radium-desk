<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareBulkDocumentOutcome;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use App\Support\Inventory\InventoryBranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HardwareShipmentBulkDocumentsService
{
    public function __construct(
        private readonly ShiprocketGateway $gateway,
    ) {}

    /**
     * @param  list<int>  $fulfilmentIds
     */
    public function downloadLabels(array $fulfilmentIds, ?User $actor = null): HardwareBulkDocumentOutcome
    {
        return $this->downloadBatch(
            $fulfilmentIds,
            $actor,
            'label',
            fn (array $externalShipmentIds): ShiprocketDocumentResult => $this->gateway->generateLabelBatch($externalShipmentIds),
        );
    }

    /**
     * @param  list<int>  $fulfilmentIds
     */
    public function downloadManifest(array $fulfilmentIds, ?User $actor = null): HardwareBulkDocumentOutcome
    {
        return $this->downloadBatch(
            $fulfilmentIds,
            $actor,
            'manifest',
            fn (array $externalShipmentIds): ShiprocketDocumentResult => $this->gateway->generateManifestBatch($externalShipmentIds),
            requirePickup: true,
        );
    }

    /**
     * @param  list<int>  $fulfilmentIds
     * @param  callable(list<string>): ShiprocketDocumentResult  $providerCall
     */
    private function downloadBatch(
        array $fulfilmentIds,
        ?User $actor,
        string $document,
        callable $providerCall,
        bool $requirePickup = false,
    ): HardwareBulkDocumentOutcome {
        $this->assertProviderCallable();

        $orderedIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $fulfilmentIds)));
        if ($orderedIds === []) {
            throw ValidationException::withMessages([
                'fulfilment_ids' => 'Select at least one hardware fulfilment.',
            ]);
        }

        $fulfilments = HardwareFulfilment::query()
            ->whereIn('id', $orderedIds)
            ->with(['shipment'])
            ->get()
            ->keyBy('id');

        $eligible = [];
        $excluded = [];

        foreach ($orderedIds as $id) {
            $fulfilment = $fulfilments->get($id);
            if ($fulfilment === null) {
                $excluded[] = [
                    'source_id' => (string) $id,
                    'reason' => 'Fulfilment not found.',
                ];

                continue;
            }

            $reason = $this->ineligibilityReason($fulfilment, $actor, $requirePickup);
            if ($reason !== null) {
                $excluded[] = [
                    'source_id' => (string) $fulfilment->source_id,
                    'reason' => $reason,
                ];

                continue;
            }

            $shipment = $this->boundShipment($fulfilment);
            $eligible[] = [
                'fulfilment' => $fulfilment,
                'shipment' => $shipment,
                'external_shipment_id' => (string) $shipment->external_shipment_id,
            ];
        }

        if ($eligible === []) {
            throw ValidationException::withMessages([
                'fulfilment_ids' => $this->exclusionSummary($excluded),
            ]);
        }

        $externalShipmentIds = array_map(
            static fn (array $row): string => $row['external_shipment_id'],
            $eligible,
        );

        try {
            $result = $providerCall($externalShipmentIds);
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Reconcile before retrying bulk '.$document.'.',
            ]);
        } catch (ShiprocketNonRetryableException|ShiprocketDisabledException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }

        if ($result->retryable) {
            throw ValidationException::withMessages([
                'shipping' => ($result->error ?? 'Shiprocket bulk '.$document.' is retryable.').' Reconcile before retrying.',
            ]);
        }

        if ($result->status !== 'generated' || ($result->url === null && $result->documentId === null)) {
            $failed = array_map(
                static fn (array $row): array => [
                    'source_id' => (string) $row['fulfilment']->source_id,
                    'reason' => $result->error ?? 'Shiprocket rejected bulk '.$document.' generation.',
                ],
                $eligible,
            );

            return new HardwareBulkDocumentOutcome(
                downloadUrl: null,
                succeededSourceIds: [],
                excluded: $excluded,
                failed: $failed,
            );
        }

        $succeededSourceIds = [];
        DB::transaction(function () use ($eligible, $document, $result, $actor, &$succeededSourceIds): void {
            foreach ($eligible as $row) {
                $fulfilment = $row['fulfilment'];
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($row['shipment']->id);
                $succeededSourceIds[] = (string) $fulfilment->source_id;

                if ($document === 'label' && ! filled($shipment->label_url) && $result->url !== null) {
                    $shipment->forceFill([
                        'label_url' => $result->url,
                        'label_fetched_at' => now(),
                        'last_error' => null,
                    ])->save();
                    $this->recordShipmentEvent($shipment, 'label_generated', [
                        'label_url' => $result->url,
                        'bulk' => true,
                    ]);
                    $this->recordFulfilmentEvent($fulfilment, $actor, 'hardware_label_generated', [
                        'shipment_id' => $shipment->id,
                        'provider' => $result->provider,
                        'correlation_id' => $shipment->correlation_id,
                        'result' => $result->status,
                        'external_shipment_id' => $shipment->external_shipment_id,
                        'bulk' => true,
                    ]);
                }

                if ($document === 'manifest'
                    && ! filled($shipment->manifest_url)
                    && ! filled($shipment->manifest_id)
                    && ($result->url !== null || $result->documentId !== null)) {
                    $shipment->forceFill([
                        'manifest_url' => $result->url,
                        'manifest_id' => $result->documentId ?? $shipment->manifest_id,
                        'manifest_generated_at' => now(),
                        'last_error' => null,
                    ])->save();
                    $this->recordShipmentEvent($shipment, 'manifest_generated', [
                        'manifest_url' => $result->url,
                        'manifest_id' => $result->documentId,
                        'bulk' => true,
                    ]);
                    $this->recordFulfilmentEvent($fulfilment, $actor, 'hardware_manifest_generated', [
                        'shipment_id' => $shipment->id,
                        'provider' => $result->provider,
                        'correlation_id' => $shipment->correlation_id,
                        'result' => $result->status,
                        'external_shipment_id' => $shipment->external_shipment_id,
                        'manifest_id' => $result->documentId,
                        'bulk' => true,
                    ]);
                }
            }
        });

        return new HardwareBulkDocumentOutcome(
            downloadUrl: $result->url,
            succeededSourceIds: $succeededSourceIds,
            excluded: $excluded,
            failed: [],
        );
    }

    private function ineligibilityReason(
        HardwareFulfilment $fulfilment,
        ?User $actor,
        bool $requirePickup,
    ): ?string {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            return 'Frozen pending hardware order.';
        }

        if ($actor !== null && $fulfilment->fulfilment_branch_id !== null) {
            $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
            if ($branch === null) {
                return 'Hardware fulfilment branch is missing.';
            }

            try {
                InventoryBranchScope::assertCanOperate($actor, $branch);
            } catch (HttpException) {
                return 'Not authorized for this fulfilment branch.';
            }
        }

        $shipment = $this->boundShipment($fulfilment);
        if ($shipment === null) {
            return 'No bound provider shipment.';
        }

        if ($fulfilment->state !== HardwareFulfilmentState::AwbAssigned) {
            return 'AWB is not assigned on this fulfilment.';
        }

        if (! filled($shipment->awb)) {
            return 'Missing provider AWB.';
        }

        if ($requirePickup && $shipment->pickup_requested_at === null) {
            return 'Pickup has not been requested.';
        }

        return null;
    }

    private function boundShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        $shipment = $fulfilment->shipment;
        if ($shipment === null && $fulfilment->shipment_id !== null) {
            $shipment = Shipment::query()->find($fulfilment->shipment_id);
        }
        if ($shipment === null) {
            $shipment = Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
        }

        if ($shipment === null || ! $shipment->isBound()) {
            return null;
        }

        return $shipment;
    }

    /**
     * @param  list<array{source_id: string, reason: string}>  $excluded
     */
    private function exclusionSummary(array $excluded): string
    {
        $parts = array_map(
            static fn (array $row): string => $row['source_id'].': '.$row['reason'],
            $excluded,
        );

        return 'No eligible fulfilments in this selection. '.implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordShipmentEvent(Shipment $shipment, string $activity, array $payload): void
    {
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => $activity,
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordFulfilmentEvent(
        HardwareFulfilment $fulfilment,
        ?User $actor,
        string $reason,
        array $payload,
    ): void {
        HardwareFulfilmentEvent::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'from_state' => $fulfilment->state,
            'to_state' => $fulfilment->state,
            'actor_type' => $actor !== null ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'payload' => array_merge(['reason' => $reason], $payload),
            'created_at' => now(),
        ]);
    }

    private function assertProviderCallable(): void
    {
        app(HardwareShipmentEligibility::class)->assertProviderConfigured();

        if ($this->gateway instanceof NullShiprocketGateway || $this->gateway->provider() === 'none') {
            throw ValidationException::withMessages([
                'shipping' => NullShiprocketGateway::MESSAGE,
            ]);
        }
    }
}
