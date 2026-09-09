<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentState;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\InventoryBranch;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Services\HardwareFulfilment\Data\HardwareShipmentCourierQuote;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\Shipping\NullShiprocketGateway;
use App\Support\HardwareFulfilment\HardwareConfigurableVariantDisplay;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Validation\ValidationException;

class HardwareShipmentEligibility
{
    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwarePickupResolver $pickups,
        private readonly ShiprocketGateway $gateway,
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareFulfilmentCountryCorrectionService $countries,
        private readonly HardwareShipmentCollectionModeResolver $collectionModes,
    ) {}

    /**
     * @return array{
     *     invoice: StatutoryInvoice,
     *     serials: list<string>,
     *     branch: InventoryBranch,
     *     pickup: string,
     *     shipping: array<string, string>,
     *     parcel: array{weight: float, length: float, breadth: float, height: float},
     *     parcel_source: string
     * }
     */
    public function require(HardwareFulfilment $fulfilment): array
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }

        $this->workflow->assertCanCreateShipment($fulfilment);

        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        $this->requirePayment($fulfilment, $order);
        $invoice = $this->requireInvoice($fulfilment, $order);
        $serials = $this->requireSerials($fulfilment, $order);
        $branch = $this->requireBranch($fulfilment);
        $this->requireConsistentPhysicalBranch($fulfilment, $branch);
        $pickup = $this->pickups->requireForBranch($branch);
        $shipping = $this->requireShippingAddress($order, $fulfilment);
        [$parcel, $parcelSource] = $this->requireParcel($order, $fulfilment);

        return [
            'invoice' => $invoice,
            'serials' => $serials,
            'branch' => $branch,
            'pickup' => $pickup,
            'shipping' => $shipping,
            'parcel' => $parcel,
            'parcel_source' => $parcelSource,
        ];
    }

    public function inspect(HardwareFulfilment $fulfilment): HardwareShipmentReadiness
    {
        $fulfilment->loadMissing([
            'commerceOrder.items',
            'serials.inventorySerial.branch',
            'fulfilmentBranch',
            'shipment',
        ]);

        $order = $fulfilment->commerceOrder;
        $blockers = [];
        $shipment = $this->existingShipment($fulfilment);
        $alreadyCreated = $shipment !== null && $shipment->isBound();

        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            $blockers[] = 'Frozen pending hardware orders cannot be shipped.';
        }

        if ($order === null) {
            $blockers[] = 'Hardware fulfilment is missing its commerce order.';
        } elseif (! $this->isPaid($fulfilment, $order)) {
            $blockers[] = 'Payment not verified';
        }

        $serials = $order === null ? [] : $this->allocatedSerials($fulfilment);
        if ($serials === [] && ! $alreadyCreated) {
            $blockers[] = 'Serial allocation required';
        } elseif ($order !== null && $serials !== [] && count($serials) !== $this->requiredPhysicalQty($order) && ! $alreadyCreated) {
            $blockers[] = 'Serial allocation required';
        }

        $invoice = $this->linkedInvoice($fulfilment, $order);
        if (($invoice === null || ! filled($invoice->invoice_number)) && ! $alreadyCreated) {
            $blockers[] = 'Invoice required';
        }

        $physicalCodes = $this->physicalBranchCodes($fulfilment);
        if (count($physicalCodes) > 1) {
            $blockers[] = 'Mixed physical branches';
        }

        $branch = $fulfilment->fulfilmentBranch;
        if ($branch === null || ! $branch->is_active) {
            if ($serials !== [] || $physicalCodes !== []) {
                $blockers[] = 'Pickup branch unknown';
            }
        } elseif (! in_array($branch->code, ['DELHI-RETAIL', 'MUMBAI'], true)) {
            $blockers[] = 'Pickup branch unknown';
        } elseif ($physicalCodes !== [] && ! isset($physicalCodes[$branch->code])) {
            $blockers[] = 'Mixed physical branches';
        }

        $pickup = null;
        $pickupPostcode = null;
        if ($branch !== null) {
            try {
                $pickup = $this->pickups->requireForBranch($branch);
            } catch (ValidationException) {
                if ($branch->is_active && in_array($branch->code, ['DELHI-RETAIL', 'MUMBAI'], true)) {
                    $blockers[] = 'Pickup location is not configured';
                } elseif (! in_array('Pickup branch unknown', $blockers, true)) {
                    $blockers[] = 'Pickup branch unknown';
                }
            }

            try {
                $pickupPostcode = $this->pickups->requirePostcodeForBranch($branch);
            } catch (ValidationException) {
                if ($branch->is_active && in_array($branch->code, ['DELHI-RETAIL', 'MUMBAI'], true) && $pickup !== null) {
                    $blockers[] = 'Pickup postcode is not configured';
                }
            }
        }

        $shipping = null;
        $countryMissing = $this->countryMissing($fulfilment, $order);
        if ($order !== null) {
            try {
                $shipping = $this->requireShippingAddress($order, $fulfilment);
            } catch (ValidationException) {
                $blockers[] = 'Shipping address incomplete';
            }
        }

        $parcel = null;
        $parcelSource = 'unavailable';
        if ($order !== null) {
            try {
                [$parcel, $parcelSource] = $this->requireParcel($order, $fulfilment);
            } catch (ValidationException) {
                $blockers[] = 'Parcel packaging not attached';
            }
        }

        $providerRejection = null;
        if ($shipment !== null && $shipment->failure_class === 'provider_rejected' && ! $alreadyCreated) {
            $providerRejection = $this->operatorProviderRejection($shipment->last_error);
        }

        if (! $this->providerReady()) {
            $blockers[] = 'Shipping is not enabled';
        }

        $blockers = array_values(array_unique($blockers));
        $needsReconcile = $shipment !== null
            && ! $alreadyCreated
            && in_array($shipment->failure_class, ['ambiguous', 'retryable'], true);
        $localReady = ! $alreadyCreated
            && $blockers === []
            && $fulfilment->state !== null
            && $fulfilment->state->value === 'invoice_issued';

        if (! $localReady && ! $alreadyCreated && $fulfilment->state?->value !== 'invoice_issued') {
            if ($serials === []) {
                $blockers = $this->prependUnique($blockers, 'Serial allocation required');
            }
            if ($invoice === null || ! filled($invoice->invoice_number)) {
                $blockers = $this->prependUnique($blockers, 'Invoice required');
            }
        }

        $collection = $this->collectionModes->forFulfilment($fulfilment);
        $fingerprint = null;
        if ($localReady && $pickup !== null && $pickupPostcode !== null && $shipping !== null && $parcel !== null) {
            $fingerprint = HardwareShipmentCourierQuote::fingerprint(
                [
                    'pickup' => $pickup,
                    'shipping' => $shipping,
                    'parcel' => $parcel,
                    'parcel_source' => $parcelSource,
                ],
                $pickupPostcode,
                $collection->serviceabilityCod(),
                filled($shipment?->external_order_id) ? (string) $shipment->external_order_id : null,
            );
        }

        $optionsFresh = $fingerprint !== null
            && HardwareShipmentCourierQuote::isFresh($fulfilment, $fingerprint);
        $validSelection = $fingerprint !== null
            && HardwareShipmentCourierQuote::hasValidSelection($fulfilment, $fingerprint);
        $courierOptions = $optionsFresh ? HardwareShipmentCourierQuote::options($fulfilment) : [];
        $recommendationReturned = $optionsFresh
            && HardwareShipmentCourierQuote::recommendationReturned($fulfilment);
        $canFetchCourierOptions = $localReady && ! $needsReconcile;
        $canCreate = $needsReconcile
            ? $localReady
            : ($localReady && $validSelection);
        $canAssignAwb = $alreadyCreated
            && ! filled($shipment?->awb)
            && $fulfilment->state?->value === 'shipment_created'
            && (filled($fulfilment->selected_courier_id) || filled($shipment?->courier_id));

        if ($localReady && ! $needsReconcile && ! $validSelection) {
            $blockers = $this->prependUnique($blockers, 'Courier selection required');
        }

        $catalog = $this->snapshots->catalogPackaging($fulfilment);
        $country = $this->countries->resolvedCountry($fulfilment);
        $awbReady = $alreadyCreated
            && filled($shipment?->awb)
            && $fulfilment->state === HardwareFulfilmentState::AwbAssigned;
        $labelUrl = filled($shipment?->label_url) ? (string) $shipment->label_url : null;
        $manifestUrl = filled($shipment?->manifest_url) ? (string) $shipment->manifest_url : null;
        $manifestId = filled($shipment?->manifest_id) ? (string) $shipment->manifest_id : null;
        $pickupRequested = $shipment?->pickup_requested_at !== null;
        $beforeLabel = $this->packageEvidence($fulfilment, HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel);
        $labelApplied = $this->packageEvidence($fulfilment, HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied);
        $readyForPickup = $fulfilment->ready_for_pickup_at !== null;
        $notTerminal = ! in_array($fulfilment->state, [
            HardwareFulfilmentState::Shipped,
            HardwareFulfilmentState::Synced,
        ], true);

        return new HardwareShipmentReadiness(
            canCreate: $canCreate,
            blockers: array_values(array_unique($blockers)),
            status: $this->shipmentStatusLabel($shipment, $alreadyCreated),
            pickupBranch: $branch?->code,
            pickupLocation: $pickup,
            shipTo: $this->formatShipTo($shipping),
            parcel: $this->formatParcel($parcel),
            invoice: $invoice?->invoice_number,
            serials: $serials,
            order: $order?->order_no ?? $fulfilment->source_id,
            product: $this->productLabel($order),
            alreadyCreated: $alreadyCreated,
            actionLabel: $needsReconcile ? 'Reconcile Shipment' : 'Create Shipment',
            parcelSource: $parcelSource,
            catalogPackaging: $catalog['label'] ?? null,
            catalogVerified: (bool) ($catalog['verified'] ?? false),
            countryMissing: $countryMissing,
            canAttachSnapshot: $this->snapshots->canAttach($fulfilment),
            canAttachMeasuredParcel: $this->snapshots->canAttachMeasured($fulfilment),
            canCorrectCountry: $this->countries->canCorrect($fulfilment),
            payment: ($order !== null && $this->isPaid($fulfilment, $order)) ? 'Paid' : 'Not verified',
            awb: $shipment?->awb ?: $fulfilment->awb,
            canFetchCourierOptions: $canFetchCourierOptions,
            canSelectCourier: $canFetchCourierOptions && $courierOptions !== [],
            canAssignAwb: $canAssignAwb,
            courierOptions: $courierOptions,
            selectedCourierId: $validSelection ? $fulfilment->selected_courier_id : null,
            selectedCourierName: $validSelection ? $fulfilment->selected_courier_name : null,
            recommendationReturned: $recommendationReturned,
            recommendationNote: $this->recommendationNote($optionsFresh, $recommendationReturned, $courierOptions),
            shipmentId: $shipment?->id,
            shipmentNo: $shipment?->shipment_no ?: $fulfilment->shipment_no,
            providerShipmentId: $shipment?->external_shipment_id ?: $fulfilment->provider_shipment_id,
            courier: $this->courierLabel($fulfilment, $shipment, $validSelection),
            country: $country,
            customer: $order !== null ? trim((string) $order->customer_name) : null,
            phone: $order !== null ? trim((string) $order->customer_phone) : null,
            email: $order !== null ? trim((string) $order->customer_email) : null,
            quantity: $order !== null ? $this->requiredPhysicalQty($order) : null,
            invoiceId: $invoice?->id,
            canGenerateLabel: $awbReady && $labelUrl === null && $notTerminal,
            canRequestPickup: $awbReady && ! $pickupRequested && $notTerminal,
            canGenerateManifest: $awbReady && $pickupRequested && $manifestUrl === null && $manifestId === null && $notTerminal,
            canUploadPackageBeforeLabel: $notTerminal,
            canUploadPackageLabelApplied: $awbReady && $notTerminal,
            canMarkReadyForPickup: $awbReady && $pickupRequested && ! $readyForPickup && $notTerminal,
            labelUrl: $labelUrl,
            labelStatus: $labelUrl !== null ? 'Available' : 'Not generated',
            manifestUrl: $manifestUrl,
            manifestId: $manifestId,
            manifestStatus: ($manifestUrl !== null || $manifestId !== null) ? 'Available' : 'Not generated',
            pickupStatus: $pickupRequested ? 'Requested' : 'Not requested',
            packageBeforeLabelRecorded: $beforeLabel !== null,
            packageLabelAppliedRecorded: $labelApplied !== null,
            packageBeforeLabelId: $beforeLabel?->id,
            packageLabelAppliedId: $labelApplied?->id,
            readyForPickup: $readyForPickup,
            collectionMode: $collection->value,
            collectionModeLabel: $collection->label(),
            providerRejection: $providerRejection,
            pickupRequestedAt: $shipment?->pickup_requested_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            volumetricWeight: $this->formatVolumetric($parcel),
            actualWeight: $parcel !== null ? number_format($parcel['weight'], 2, '.', '').' kg' : null,
        );
    }

    public function assertProviderConfigured(): void
    {
        if (! (bool) config('shipping.enabled')) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket is disabled. No provider call was made.',
            ]);
        }

        $provider = (string) config('shipping.provider', 'none');
        if ($provider === '' || $provider === 'none') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket provider is not configured. No provider call was made.',
            ]);
        }
    }

    private function requireInvoice(HardwareFulfilment $fulfilment, CommerceOrder $order): StatutoryInvoice
    {
        $invoiceId = $fulfilment->statutory_invoice_id ?? $order->statutory_invoice_id;
        if ($invoiceId === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware shipment requires a linked statutory invoice.',
            ]);
        }

        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null || ! filled($invoice->invoice_number)) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware shipment requires a statutory invoice number. P5 does not mint invoices.',
            ]);
        }

        return $invoice;
    }

    /**
     * @return list<string>
     */
    private function requireSerials(HardwareFulfilment $fulfilment, CommerceOrder $order): array
    {
        $serials = array_values(array_map(
            static fn (string $serial): string => InventorySerialNumber::normalize($serial),
            $this->workflow->allocatedSerialNumbers($fulfilment),
        ));

        if ($serials === []) {
            throw ValidationException::withMessages([
                'serials' => 'Hardware shipment requires persisted allocated serials.',
            ]);
        }

        $required = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $required += (int) $item->qty;
            }
        }

        if (count($serials) !== $required) {
            throw ValidationException::withMessages([
                'serials' => sprintf(
                    'Allocated serial count %d does not match physical quantity %d.',
                    count($serials),
                    $required,
                ),
            ]);
        }

        $locked = $fulfilment->metadata['invoice_serials'] ?? null;
        if (is_array($locked) && $locked !== []) {
            $normalizedLocked = array_values(array_map(
                static fn (mixed $serial): string => InventorySerialNumber::normalize((string) $serial),
                $locked,
            ));
            $a = $serials;
            $b = $normalizedLocked;
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);
            if ($a !== $b) {
                throw ValidationException::withMessages([
                    'serials' => 'Shipment serials differ from the invoice-locked allocation.',
                ]);
            }
        }

        return $serials;
    }

    private function requireBranch(HardwareFulfilment $fulfilment): InventoryBranch
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware shipment requires a fulfilment branch. Customer state cannot substitute.',
            ]);
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is missing or inactive.',
            ]);
        }

        return $branch;
    }

    /**
     * @return array<string, string>
     */
    private function requireShippingAddress(CommerceOrder $order, HardwareFulfilment $fulfilment): array
    {
        $structured = $order->shipping_address_structured;
        if (! is_array($structured)) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a structured shipping address. Billing address is not substituted.',
            ]);
        }

        $required = ['line1', 'city', 'state', 'pincode', 'country'];
        $out = [];
        foreach ($required as $key) {
            $value = trim((string) ($structured[$key] ?? ''));
            if ($key === 'country') {
                $value = trim($this->countries->resolvedCountry($fulfilment));
            }
            if ($value === '') {
                throw ValidationException::withMessages([
                    'address' => sprintf('Shipping address is missing %s. Billing address is not substituted.', $key),
                ]);
            }
            $out[$key] = $value;
        }

        $out['line2'] = trim((string) ($structured['line2'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone) ?? '';
        if (strlen($phone) < 10) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer phone of at least 10 digits.',
            ]);
        }
        $out['phone'] = $phone;

        $email = trim((string) $order->customer_email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer email. One is not invented.',
            ]);
        }
        $out['email'] = $email;
        $out['name'] = trim((string) $order->customer_name);
        if ($out['name'] === '') {
            throw ValidationException::withMessages([
                'address' => 'Hardware shipment requires a customer name.',
            ]);
        }

        return $out;
    }

    /**
     * @return array{0: array{weight: float, length: float, breadth: float, height: float}, 1: string}
     */
    private function requireParcel(CommerceOrder $order, HardwareFulfilment $fulfilment): array
    {
        $ingest = $this->snapshots->completeIngestParcel(is_array($order->parcel) ? $order->parcel : null);
        if ($ingest !== null) {
            return [$ingest, 'ingest'];
        }

        $snapshot = $this->snapshots->validSnapshot($fulfilment);
        if ($snapshot !== null) {
            $source = $this->snapshots->snapshotSource($fulfilment) === HardwareFulfilmentParcelSnapshotService::SOURCE_MEASURED
                ? 'measured'
                : 'snapshot';

            return [$snapshot, $source];
        }

        throw ValidationException::withMessages([
            'parcel' => 'Hardware shipment requires persisted parcel dimensions. Defaults are not invented.',
        ]);
    }

    private function countryMissing(HardwareFulfilment $fulfilment, ?CommerceOrder $order): bool
    {
        if ($order === null) {
            return true;
        }

        return $this->countries->resolvedCountry($fulfilment) === null;
    }

    private function requirePayment(HardwareFulfilment $fulfilment, CommerceOrder $order): void
    {
        if (! $this->isPaid($fulfilment, $order)) {
            throw ValidationException::withMessages([
                'payment' => 'Hardware shipment requires verified payment.',
            ]);
        }
    }

    private function requireConsistentPhysicalBranch(HardwareFulfilment $fulfilment, InventoryBranch $branch): void
    {
        $codes = $this->physicalBranchCodes($fulfilment);
        if (count($codes) > 1) {
            throw ValidationException::withMessages([
                'branch' => 'Selected serials belong to multiple physical stock branches. One hardware fulfilment cannot mix Delhi and Mumbai stock.',
            ]);
        }

        $physical = array_key_first($codes);
        if ($physical !== null && $physical !== $branch->code) {
            throw ValidationException::withMessages([
                'branch' => 'Fulfilment branch does not match the allocated serial stock location.',
            ]);
        }
    }

    private function isPaid(HardwareFulfilment $fulfilment, CommerceOrder $order): bool
    {
        $status = strtolower(trim((string) $order->payment_status));

        return in_array($status, ['paid', 'success', 'captured'], true)
            || $order->paid_at !== null
            || $fulfilment->paid_recognized_at !== null;
    }

    /**
     * @return list<string>
     */
    private function allocatedSerials(HardwareFulfilment $fulfilment): array
    {
        return array_values(array_map(
            static fn (string $serial): string => InventorySerialNumber::normalize($serial),
            $this->workflow->allocatedSerialNumbers($fulfilment),
        ));
    }

    private function requiredPhysicalQty(CommerceOrder $order): int
    {
        $required = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $required += (int) $item->qty;
            }
        }

        return $required;
    }

    private function linkedInvoice(HardwareFulfilment $fulfilment, ?CommerceOrder $order): ?StatutoryInvoice
    {
        $invoiceId = $fulfilment->statutory_invoice_id ?? $order?->statutory_invoice_id;
        if ($invoiceId === null) {
            return null;
        }

        return StatutoryInvoice::query()->find($invoiceId);
    }

    /**
     * @return array<string, true>
     */
    private function physicalBranchCodes(HardwareFulfilment $fulfilment): array
    {
        $fulfilment->loadMissing('serials.inventorySerial.branch');
        $codes = [];
        foreach ($fulfilment->serials as $row) {
            $code = trim((string) ($row->inventorySerial?->branch?->code ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    private function operatorProviderRejection(?string $lastError): string
    {
        $message = trim((string) $lastError);
        if ($message === '') {
            $message = 'Provider validation error';
        }

        if (! str_starts_with(strtolower($message), 'shiprocket rejected')) {
            $message = 'Shiprocket rejected shipment creation: '.$message;
        }

        return $message;
    }

    private function shipmentStatusLabel(?Shipment $shipment, bool $alreadyCreated): string
    {
        if ($alreadyCreated) {
            return filled($shipment?->awb) ? 'Created (AWB assigned)' : 'Created';
        }

        return match ($shipment?->failure_class) {
            'provider_rejected' => 'Not created — provider rejected',
            'ambiguous', 'retryable' => 'Not created — reconcile required',
            default => 'Not created',
        };
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

    private function providerReady(): bool
    {
        if (! (bool) config('shipping.enabled')) {
            return false;
        }

        $provider = (string) config('shipping.provider', 'none');
        if ($provider === '' || $provider === 'none') {
            return false;
        }

        return ! ($this->gateway instanceof NullShiprocketGateway)
            && $this->gateway->provider() !== 'none';
    }

    /**
     * @param  array<string, string>|null  $shipping
     */
    private function formatShipTo(?array $shipping): ?string
    {
        if ($shipping === null) {
            return null;
        }

        $parts = array_filter([
            $shipping['line1'] ?? null,
            $shipping['line2'] ?? null,
            $shipping['city'] ?? null,
            trim(($shipping['state'] ?? '').' '.($shipping['pincode'] ?? '')),
            $shipping['country'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array{weight: float, length: float, breadth: float, height: float}|null  $parcel
     */
    private function formatParcel(?array $parcel): ?string
    {
        if ($parcel === null) {
            return null;
        }

        return sprintf(
            '%s kg · %s×%s×%s cm',
            $parcel['weight'],
            $parcel['length'],
            $parcel['breadth'],
            $parcel['height'],
        );
    }

    /**
     * @param  array{weight: float, length: float, breadth: float, height: float}|null  $parcel
     */
    private function formatVolumetric(?array $parcel): ?string
    {
        if ($parcel === null) {
            return null;
        }

        $kg = HardwareShipmentVolumetricWeight::kilograms(
            $parcel['length'],
            $parcel['breadth'],
            $parcel['height'],
        );

        return number_format($kg, 2, '.', '').' kg';
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function recommendationNote(bool $optionsFresh, bool $recommendationReturned, array $options): string
    {
        if (! $optionsFresh || $options === []) {
            return '';
        }

        return $recommendationReturned
            ? 'Shiprocket Recommended'
            : 'Shiprocket returned options without a recommendation.';
    }

    private function courierLabel(HardwareFulfilment $fulfilment, ?Shipment $shipment, bool $validSelection): ?string
    {
        $name = $validSelection
            ? $fulfilment->selected_courier_name
            : ($shipment?->courier_name ?: $fulfilment->selected_courier_name);
        $id = $validSelection
            ? $fulfilment->selected_courier_id
            : ($shipment?->courier_id ?: $fulfilment->selected_courier_id);

        $name = trim((string) $name);
        $id = trim((string) $id);
        if ($name === '' && $id === '') {
            return null;
        }

        return $name !== '' && $id !== '' ? $name.' ('.$id.')' : ($name !== '' ? $name : $id);
    }

    private function productLabel(?CommerceOrder $order): ?string
    {
        if ($order === null) {
            return null;
        }

        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $label = HardwareConfigurableVariantDisplay::label($item);

                return $label !== '' ? $label : null;
            }
        }

        return null;
    }

    private function packageEvidence(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPackageEvidenceKind $kind,
    ): ?HardwareFulfilmentPackageEvidence {
        if ($fulfilment->relationLoaded('packageEvidences')) {
            return $fulfilment->packageEvidences->first(
                fn (HardwareFulfilmentPackageEvidence $row): bool => $row->kind === $kind,
            );
        }

        return HardwareFulfilmentPackageEvidence::query()
            ->where('hardware_fulfilment_id', $fulfilment->id)
            ->where('kind', $kind)
            ->first();
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function prependUnique(array $blockers, string $message): array
    {
        if (in_array($message, $blockers, true)) {
            return $blockers;
        }

        array_unshift($blockers, $message);

        return $blockers;
    }

    private function positiveNumber(mixed $value, string $field): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'parcel' => sprintf('Parcel %s is missing. Defaults are not invented.', $field),
            ]);
        }

        $number = (float) $value;
        if ($number <= 0) {
            throw ValidationException::withMessages([
                'parcel' => sprintf('Parcel %s must be greater than zero.', $field),
            ]);
        }

        return $number;
    }
}
