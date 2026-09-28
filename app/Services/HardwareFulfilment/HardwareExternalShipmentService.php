<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentShippingMethod;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\HardwareFulfilment\ExternalCourierCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HardwareExternalShipmentService
{
    private const AWB_MAX_LENGTH = 64;

    private const TRACKING_URL_MAX_LENGTH = 1024;

    /**
     * @var list<string>
     */
    private const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareFulfilmentWorkflowService $workflow,
    ) {}

    public function selectShippingMethod(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentShippingMethod $method,
        ?User $actor = null,
    ): HardwareFulfilment {
        return DB::transaction(function () use ($fulfilment, $method, $actor): HardwareFulfilment {
            $locked = $this->lockFulfilment($fulfilment);
            $this->assertMethodMutable($locked);
            $this->assertCanChooseMethod($locked);

            if ($locked->resolvedShippingMethod() === $method) {
                return $locked;
            }

            if ($locked->resolvedShippingMethod() === HardwareFulfilmentShippingMethod::External
                && $method === HardwareFulfilmentShippingMethod::Shiprocket) {
                throw ValidationException::withMessages([
                    'shipping_method' => 'External shipments cannot switch back to Shiprocket.',
                ]);
            }

            if ($method === HardwareFulfilmentShippingMethod::Shiprocket
                && $this->existingShipment($locked)?->isExternal() === true) {
                throw ValidationException::withMessages([
                    'shipping_method' => 'An external shipment already exists for this fulfilment.',
                ]);
            }

            $locked->forceFill([
                'shipping_method' => $method->value,
            ])->save();

            $this->recordShipmentEvent(
                $this->existingShipment($locked),
                'external_method_selected',
                [
                    'shipping_method' => $method->value,
                    'actor_id' => $actor?->id,
                ],
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function recordShipment(
        HardwareFulfilment $fulfilment,
        string $courierCode,
        ?string $courierDisplayName,
        string $awb,
        ?string $trackingUrl = null,
        ?string $notes = null,
        ?User $actor = null,
    ): Shipment {
        $courierCode = trim($courierCode);
        $awb = $this->normalizeAwb($awb);
        $courierName = $this->resolveCourierName($courierCode, $courierDisplayName);
        $trackingUrl = $this->normalizeTrackingUrl($trackingUrl);

        return DB::transaction(function () use (
            $fulfilment,
            $courierCode,
            $courierName,
            $awb,
            $trackingUrl,
            $notes,
            $actor,
        ): Shipment {
            $locked = $this->lockFulfilment($fulfilment);
            $this->assertCanRecordShipment($locked);
            $this->assertMethodMutable($locked);

            $existing = $this->existingShipment($locked);
            if ($existing !== null && filled($existing->awb)) {
                throw ValidationException::withMessages([
                    'awb' => 'AWB has already been recorded for this fulfilment.',
                ]);
            }

            if ($existing?->isBound() === true) {
                throw ValidationException::withMessages([
                    'shipping' => 'A Shiprocket shipment is already bound for this fulfilment.',
                ]);
            }

            $ready = $this->eligibility->quoteInputs($locked);

            $locked->forceFill([
                'shipping_method' => HardwareFulfilmentShippingMethod::External->value,
                'external_courier_code' => $courierCode,
                'external_notes' => $notes !== null ? trim($notes) : $locked->external_notes,
                'selected_courier_name' => $courierName,
                'selected_courier_id' => null,
            ])->save();

            $shipment = $existing ?? $this->createExternalShipment($locked, $ready, $courierName, $awb, $trackingUrl);

            $shipment->forceFill([
                'provider' => 'external',
                'courier_name' => $courierName,
                'courier_id' => null,
                'external_order_id' => null,
                'external_shipment_id' => null,
                'awb' => $awb,
                'tracking_url' => $trackingUrl,
                'status' => ShipmentStatus::AwbAssigned,
                'awb_assigned_at' => now(),
                'failure_class' => null,
                'last_error' => null,
            ])->save();

            $locked->forceFill([
                'shipment_id' => $shipment->id,
                'shipment_no' => $shipment->shipment_no,
                'awb' => $awb,
                'provider_awb' => $awb,
            ])->save();

            if ($locked->state === HardwareFulfilmentState::InvoiceIssued) {
                $this->workflow->transition(
                    $locked->fresh() ?? $locked,
                    HardwareFulfilmentState::ShipmentCreated,
                    actorType: $actor !== null ? 'user' : 'system',
                    actorId: $actor?->id,
                    payload: ['reason' => 'external_shipment_opened'],
                );
            }

            $fresh = $locked->fresh() ?? $locked;
            if ($fresh->state === HardwareFulfilmentState::ShipmentCreated) {
                $this->workflow->transition(
                    $fresh,
                    HardwareFulfilmentState::AwbAssigned,
                    actorType: $actor !== null ? 'user' : 'system',
                    actorId: $actor?->id,
                    payload: [
                        'reason' => 'external_awb_recorded',
                        'awb' => $awb,
                    ],
                );
            }

            $this->recordShipmentEvent($shipment->fresh(), 'external_courier_selected', [
                'courier_code' => $courierCode,
                'courier_name' => $courierName,
                'actor_id' => $actor?->id,
            ]);
            $this->recordShipmentEvent($shipment->fresh(), 'external_awb_recorded', [
                'awb' => $awb,
                'actor_id' => $actor?->id,
            ]);
            if ($trackingUrl !== null) {
                $this->recordShipmentEvent($shipment->fresh(), 'external_tracking_updated', [
                    'tracking_url' => $trackingUrl,
                    'actor_id' => $actor?->id,
                ]);
            }

            return $shipment->fresh() ?? $shipment;
        });
    }

    public function uploadLabel(HardwareFulfilment $fulfilment, UploadedFile $file, ?User $actor = null): Shipment
    {
        return $this->uploadDocument($fulfilment, $file, 'label', 'external_label_uploaded', $actor);
    }

    public function uploadManifest(HardwareFulfilment $fulfilment, UploadedFile $file, ?User $actor = null): Shipment
    {
        return $this->uploadDocument($fulfilment, $file, 'manifest', 'external_manifest_uploaded', $actor);
    }

    public function dispatch(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareFulfilment
    {
        $locked = $fulfilment->fresh(['shipment']) ?? $fulfilment;
        $this->assertCanDispatch($locked);

        return DB::transaction(function () use ($locked, $actor): HardwareFulfilment {
            $shipment = $this->requireExternalShipment($locked);
            $shipment->forceFill([
                'dispatched_at' => now(),
                'dispatched_by_user_id' => $actor?->id,
            ])->save();

            $updated = $this->workflow->markShipped(
                $locked->fresh(['shipment']) ?? $locked,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
            );

            $this->recordShipmentEvent($shipment->fresh(), 'external_dispatched', [
                'awb' => $shipment->awb,
                'courier_name' => $shipment->courier_name,
                'actor_id' => $actor?->id,
            ]);

            return $updated;
        });
    }

    public function downloadLabel(HardwareFulfilment $fulfilment): array
    {
        $shipment = $this->requireExternalShipment($fulfilment);
        if (! filled($shipment->label_path) || ! filled($shipment->label_disk)) {
            throw ValidationException::withMessages([
                'label' => 'No external label has been uploaded for this shipment.',
            ]);
        }

        return $this->downloadDocument($shipment, 'label');
    }

    public function downloadManifest(HardwareFulfilment $fulfilment): array
    {
        $shipment = $this->requireExternalShipment($fulfilment);
        if (! filled($shipment->manifest_path) || ! filled($shipment->manifest_disk)) {
            throw ValidationException::withMessages([
                'manifest' => 'No external manifest has been uploaded for this shipment.',
            ]);
        }

        return $this->downloadDocument($shipment, 'manifest');
    }

    public function assertCanChooseMethod(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->state !== HardwareFulfilmentState::InvoiceIssued) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Shipping method can be chosen only after invoice issuance.',
            ]);
        }

        if ($this->methodLocked($fulfilment)) {
            throw ValidationException::withMessages([
                'shipping_method' => 'Shipping method is locked after AWB assignment.',
            ]);
        }
    }

    public function assertCanRecordShipment(HardwareFulfilment $fulfilment): void
    {
        $this->assertNotFrozen($fulfilment);

        if ($fulfilment->state !== HardwareFulfilmentState::InvoiceIssued) {
            throw ValidationException::withMessages([
                'fulfilment' => 'External shipment recording requires INVOICE_ISSUED.',
            ]);
        }

        if ($this->methodLocked($fulfilment)) {
            throw ValidationException::withMessages([
                'awb' => 'AWB cannot be changed after dispatch.',
            ]);
        }
    }

    public function assertCanDispatch(HardwareFulfilment $fulfilment): void
    {
        $this->assertNotFrozen($fulfilment);
        $shipment = $this->requireExternalShipment($fulfilment);

        if ($fulfilment->state !== HardwareFulfilmentState::AwbAssigned) {
            throw ValidationException::withMessages([
                'state' => 'External dispatch requires AWB_ASSIGNED.',
            ]);
        }

        if (! filled($shipment->awb)) {
            throw ValidationException::withMessages([
                'awb' => 'External dispatch requires a recorded AWB.',
            ]);
        }
    }

    public function methodLocked(HardwareFulfilment $fulfilment): bool
    {
        if (in_array($fulfilment->state, [HardwareFulfilmentState::Shipped, HardwareFulfilmentState::Synced], true)) {
            return true;
        }

        if (filled($fulfilment->awb)) {
            return true;
        }

        $shipment = $this->existingShipment($fulfilment);

        return $shipment !== null && filled($shipment->awb);
    }

    public function normalizeAwb(string $awb): string
    {
        $awb = trim($awb);
        if ($awb === '') {
            throw ValidationException::withMessages([
                'awb' => 'AWB / tracking number is required.',
            ]);
        }

        if (strlen($awb) > self::AWB_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'awb' => sprintf('AWB / tracking number must be %d characters or fewer.', self::AWB_MAX_LENGTH),
            ]);
        }

        if (Shipment::query()->where('awb', $awb)->exists()) {
            throw ValidationException::withMessages([
                'awb' => 'This AWB / tracking number is already in use.',
            ]);
        }

        return $awb;
    }

    public function normalizeTrackingUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (strlen($url) > self::TRACKING_URL_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'tracking_url' => sprintf('Tracking URL must be %d characters or fewer.', self::TRACKING_URL_MAX_LENGTH),
            ]);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw ValidationException::withMessages([
                'tracking_url' => 'Tracking URL must use HTTPS.',
            ]);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'tracking_url' => 'Tracking URL is not valid.',
            ]);
        }

        return $url;
    }

    public function resolveCourierName(string $courierCode, ?string $displayName): string
    {
        if (! ExternalCourierCatalog::isValidCode($courierCode)) {
            throw ValidationException::withMessages([
                'courier_code' => 'Select a valid external courier.',
            ]);
        }

        if (ExternalCourierCatalog::requiresDisplayName($courierCode)) {
            $displayName = trim((string) $displayName);
            if ($displayName === '') {
                throw ValidationException::withMessages([
                    'courier_name' => 'Courier name is required when Other is selected.',
                ]);
            }

            return $displayName;
        }

        return ExternalCourierCatalog::label($courierCode) ?? $courierCode;
    }

    private function uploadDocument(
        HardwareFulfilment $fulfilment,
        UploadedFile $file,
        string $kind,
        string $activity,
        ?User $actor,
    ): Shipment {
        $this->validateUploadedDocument($file);

        return DB::transaction(function () use ($fulfilment, $file, $kind, $activity, $actor): Shipment {
            $locked = $this->lockFulfilment($fulfilment);
            $shipment = $this->requireExternalShipment($locked);

            if (in_array($locked->state, [HardwareFulfilmentState::Shipped, HardwareFulfilmentState::Synced], true)) {
                throw ValidationException::withMessages([
                    $kind => 'Documents cannot be replaced after dispatch.',
                ]);
            }

            $disk = 'local';
            $directory = 'private/hardware-external-shipment-documents/'.$shipment->id;
            $stored = $file->store($directory, $disk);
            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([
                    $kind => 'The document could not be stored.',
                ]);
            }

            $pathColumn = $kind.'_path';
            $diskColumn = $kind.'_disk';
            $previousPath = $shipment->{$pathColumn};
            $previousDisk = $shipment->{$diskColumn} ?: $disk;

            $updates = [
                $diskColumn => $disk,
                $pathColumn => $stored,
            ];
            if ($kind === 'label') {
                $updates['label_source'] = 'upload';
            }

            $shipment->forceFill($updates)->save();

            if (filled($previousPath) && $previousPath !== $stored) {
                Storage::disk($previousDisk)->delete($previousPath);
            }

            $this->recordShipmentEvent($shipment->fresh(), $activity, [
                'filename' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize() ?: null,
                'mime_type' => $file->getClientMimeType(),
                'actor_id' => $actor?->id,
            ]);

            return $shipment->fresh() ?? $shipment;
        });
    }

    /**
     * @param  array<string, mixed>  $ready
     */
    private function createExternalShipment(
        HardwareFulfilment $fulfilment,
        array $ready,
        string $courierName,
        string $awb,
        ?string $trackingUrl,
    ): Shipment {
        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        return Shipment::query()->create([
            'shipment_no' => 'HW-'.$fulfilment->source_id,
            'commerce_order_id' => $order->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'external',
            'status' => ShipmentStatus::AwbAssigned,
            'invoice_number' => $ready['invoice']->invoice_number,
            'serial_numbers' => $ready['serials'],
            'pickup_location' => $ready['pickup'],
            'courier_name' => $courierName,
            'awb' => $awb,
            'tracking_url' => $trackingUrl,
            'idempotency_key' => 'hardware:external:create:'.$fulfilment->id,
            'correlation_id' => (string) Str::uuid(),
            'create_snapshot' => [
                'shipping_method' => HardwareFulfilmentShippingMethod::External->value,
                'courier_name' => $courierName,
                'source_id' => $fulfilment->source_id,
            ],
            'awb_assigned_at' => now(),
        ]);
    }

    /**
     * @return array{disk: string, path: string, filename: string, mime: ?string}
     */
    private function downloadDocument(Shipment $shipment, string $kind): array
    {
        $path = (string) $shipment->{$kind.'_path'};
        $disk = (string) ($shipment->{$kind.'_disk'} ?: 'local');

        return [
            'disk' => $disk,
            'path' => $path,
            'filename' => basename($path),
            'mime' => $kind === 'label' ? 'application/pdf' : 'application/pdf',
        ];
    }

    private function validateUploadedDocument(UploadedFile $file): void
    {
        $mime = strtolower((string) $file->getClientMimeType());
        if (! in_array($mime, self::ALLOWED_DOCUMENT_MIMES, true)) {
            throw ValidationException::withMessages([
                'document' => 'Only PDF, JPEG, and PNG documents are allowed.',
            ]);
        }

        $maxKb = (int) config('hardware_fulfilment.external_document_max_kb', 10240);
        if ($file->getSize() !== false && $file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'document' => sprintf('Document must be %d KB or smaller.', $maxKb),
            ]);
        }
    }

    private function requireExternalShipment(HardwareFulfilment $fulfilment): Shipment
    {
        $shipment = $this->existingShipment($fulfilment);
        if ($shipment === null || ! $shipment->isExternal()) {
            throw ValidationException::withMessages([
                'shipping' => 'This fulfilment does not have an external shipment.',
            ]);
        }

        return $shipment;
    }

    private function existingShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        if ($fulfilment->relationLoaded('shipment') && $fulfilment->shipment !== null) {
            return $fulfilment->shipment;
        }

        if ($fulfilment->shipment_id !== null) {
            return Shipment::query()->find($fulfilment->shipment_id);
        }

        return Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
    }

    private function lockFulfilment(HardwareFulfilment $fulfilment): HardwareFulfilment
    {
        $this->assertNotFrozen($fulfilment);

        return HardwareFulfilment::query()
            ->whereKey($fulfilment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertMethodMutable(HardwareFulfilment $fulfilment): void
    {
        if ($this->methodLocked($fulfilment)) {
            throw ValidationException::withMessages([
                'shipping_method' => 'Shipping method cannot be changed after AWB assignment.',
            ]);
        }

        $shipment = $this->existingShipment($fulfilment);
        if ($shipment?->isBound() === true) {
            throw ValidationException::withMessages([
                'shipping_method' => 'Shiprocket-bound shipments cannot switch to external shipping.',
            ]);
        }
    }

    private function assertNotFrozen(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordShipmentEvent(?Shipment $shipment, string $activity, array $payload = []): void
    {
        if ($shipment === null) {
            return;
        }

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'external',
            'activity' => $activity,
            'awb' => filled($shipment->awb) ? (string) $shipment->awb : null,
            'payload' => $payload,
        ]);
    }
}
