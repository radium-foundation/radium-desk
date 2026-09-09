<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareShipmentReadiness
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $serials
     */
    public function __construct(
        public readonly bool $canCreate,
        public readonly array $blockers,
        public readonly string $status,
        public readonly ?string $pickupBranch,
        public readonly ?string $pickupLocation,
        public readonly ?string $shipTo,
        public readonly ?string $parcel,
        public readonly ?string $invoice,
        public readonly array $serials,
        public readonly ?string $order,
        public readonly ?string $product,
        public readonly bool $alreadyCreated = false,
        public readonly string $provider = 'Shiprocket',
        public readonly string $actionLabel = 'Create Shipment',
        public readonly string $parcelSource = 'unavailable',
        public readonly ?string $catalogPackaging = null,
        public readonly bool $catalogVerified = false,
        public readonly bool $countryMissing = false,
        public readonly bool $canAttachSnapshot = false,
        public readonly bool $canCorrectCountry = false,
        public readonly string $payment = 'Not verified',
        public readonly ?string $awb = null,
        public readonly bool $canFetchCourierOptions = false,
        public readonly bool $canSelectCourier = false,
        public readonly bool $canAssignAwb = false,
        public readonly array $courierOptions = [],
        public readonly ?string $selectedCourierId = null,
        public readonly ?string $selectedCourierName = null,
        public readonly bool $recommendationReturned = false,
        public readonly string $recommendationNote = '',
        public readonly ?int $shipmentId = null,
        public readonly ?string $shipmentNo = null,
        public readonly ?string $providerShipmentId = null,
        public readonly ?string $courier = null,
        public readonly ?string $country = null,
        public readonly ?string $customer = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?int $quantity = null,
        public readonly ?int $invoiceId = null,
        public readonly bool $canGenerateLabel = false,
        public readonly bool $canRequestPickup = false,
        public readonly bool $canGenerateManifest = false,
        public readonly bool $canUploadPackageBeforeLabel = false,
        public readonly bool $canUploadPackageLabelApplied = false,
        public readonly bool $canMarkReadyForPickup = false,
        public readonly ?string $labelUrl = null,
        public readonly string $labelStatus = 'Not generated',
        public readonly ?string $manifestUrl = null,
        public readonly ?string $manifestId = null,
        public readonly string $manifestStatus = 'Not generated',
        public readonly string $pickupStatus = 'Not requested',
        public readonly bool $packageBeforeLabelRecorded = false,
        public readonly bool $packageLabelAppliedRecorded = false,
        public readonly ?int $packageBeforeLabelId = null,
        public readonly ?int $packageLabelAppliedId = null,
        public readonly bool $readyForPickup = false,
        public readonly string $collectionMode = 'prepaid',
        public readonly string $collectionModeLabel = 'Prepaid',
        public readonly ?string $providerRejection = null,
        public readonly ?string $pickupRequestedAt = null,
        public readonly bool $canAttachMeasuredParcel = false,
        public readonly ?string $volumetricWeight = null,
        public readonly ?string $actualWeight = null,
    ) {}

    public function packagePhotoRecorded(): bool
    {
        return $this->packageBeforeLabelRecorded || $this->packageLabelAppliedRecorded;
    }

    public function packagePhotoId(): ?int
    {
        return $this->packageBeforeLabelId ?? $this->packageLabelAppliedId;
    }
}
