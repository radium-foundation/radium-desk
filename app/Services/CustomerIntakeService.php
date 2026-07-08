<?php

namespace App\Services;

use App\Enums\CustomerIdentityType;
use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\LegacyOrder\LegacyOrderImportService;
use App\Services\RadiumBox\RadiumBoxClient;
use App\Services\SerialValidation\SerialValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerIntakeService
{
    public function __construct(
        private readonly CustomerIntakeSearchService $searchService,
        private readonly QuickServiceRequestService $quickServiceRequestService,
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly AuditLogService $auditLogService,
        private readonly RadiumBoxClient $radiumBoxClient,
        private readonly LegacyOrderImportService $legacyOrderImportService,
        private readonly SerialValidationService $serialValidationService,
    ) {}

    public function createForExistingOrder(
        User $user,
        Order $order,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
    ): Incident {
        if ($this->searchService->identityTypeForOrder($order) === CustomerIdentityType::Legacy) {
            $this->auditLogService->log(
                userId: $user->id,
                event: 'intake.legacy_customer_matched',
                auditable: $order,
                newValues: [
                    'legacy_source' => 'desk',
                    'order_id' => $order->order_id,
                ],
            );
        }

        return $this->quickServiceRequestService->createForOrder(
            user: $user,
            order: $order,
            source: $source,
            notes: $notes,
            highPriority: $highPriority,
        );
    }

    public function createLegacyFromRadiumBox(
        User $user,
        string $orderId,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
        ?string $phone = null,
    ): Incident {
        return DB::transaction(function () use ($user, $orderId, $source, $notes, $highPriority, $phone): Incident {
            $existingOrder = Order::query()->where('order_id', $orderId)->first();

            if ($existingOrder !== null) {
                return $this->createForExistingOrder(
                    user: $user,
                    order: $existingOrder,
                    source: $source,
                    notes: $notes,
                    highPriority: $highPriority,
                );
            }

            $enrichment = $this->radiumBoxClient->fetchOrderEnrichment($orderId);

            $order = Order::query()->create([
                'order_id' => $orderId,
                'customer_phone' => $phone,
                'serial_number' => $enrichment?->serialNumber,
                'product_name' => $enrichment?->deviceModel,
                'device_model' => $enrichment?->deviceModel,
                'status' => OrderStatus::Active,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'intake.legacy_customer_matched',
                auditable: $order,
                newValues: [
                    'legacy_source' => 'radiumbox',
                    'order_id' => $orderId,
                ],
            );

            return $this->quickServiceRequestService->createForOrder(
                user: $user,
                order: $order,
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
                title: 'Legacy service request — '.($enrichment?->deviceModel ?? $orderId),
            );
        });
    }

    public function importLegacyOrder(
        User $user,
        string $orderId,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
        ?string $phone = null,
    ): Incident {
        return $this->legacyOrderImportService->import(
            user: $user,
            orderId: $orderId,
            source: $source,
            notes: $notes,
            highPriority: $highPriority,
            intakePhone: $phone,
        );
    }

    public function createNewContact(
        User $user,
        NewContactIntent $intent,
        IncidentSource $source,
        ?string $customerName,
        ?string $phone,
        ?string $serialNumber,
        ?string $product,
        ?string $notes,
        bool $highPriority = false,
        bool $assignOnCreate = true,
    ): Incident {
        return DB::transaction(function () use ($user, $intent, $source, $customerName, $phone, $serialNumber, $product, $notes, $highPriority, $assignOnCreate): Incident {
            $reference = $this->incidentReferenceService->generate();
            $inquiryOrderId = Order::inquiryOrderIdFromReference($reference);

            $normalizedSerial = null;

            if ($intent->requiresSerial()) {
                if ($serialNumber === null || trim($serialNumber) === '') {
                    throw ValidationException::withMessages([
                        'serial_number' => 'Serial number is required for existing device service.',
                    ]);
                }

                if ($product === null || trim($product) === '') {
                    throw ValidationException::withMessages([
                        'product' => 'Product is required for existing device service.',
                    ]);
                }

                $originalSerial = strtoupper(trim($serialNumber));
                $validation = $this->serialValidationService->assertValid($originalSerial, $product);
                $normalizedSerial = $validation->normalizedSerial;

                $serialOwner = Order::query()
                    ->where('serial_number', $normalizedSerial)
                    ->first();

                if ($serialOwner !== null) {
                    throw ValidationException::withMessages([
                        'serial_number' => 'This serial number belongs to a different order.',
                    ]);
                }
            }

            $order = Order::query()->create([
                'order_id' => $inquiryOrderId,
                'customer_name' => $customerName,
                'customer_phone' => $phone,
                'serial_number' => $normalizedSerial,
                'product_name' => $intent->requiresProduct() ? $product : null,
                'device_model' => $intent->requiresProduct() ? $product : null,
                'status' => OrderStatus::Active,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $incident = $this->quickServiceRequestService->createForOrder(
                user: $user,
                order: $order,
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
                title: $this->titleForIntent($intent),
                assignOnCreate: $assignOnCreate,
            );

            $incident->update([
                'category' => $intent->incidentCategory(),
            ]);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'intake.new_contact_created',
                auditable: $incident,
                newValues: [
                    'intent' => $intent->value,
                    'inquiry_order_id' => $inquiryOrderId,
                    'customer_phone' => $phone,
                ],
            );

            return $incident->fresh(['order', 'assignee']);
        });
    }

    private function titleForIntent(NewContactIntent $intent): string
    {
        return match ($intent) {
            NewContactIntent::BuyDevice => 'Buy device inquiry',
            NewContactIntent::ExistingDeviceService => 'Existing device service — verification needed',
            NewContactIntent::GeneralSupport => 'General support inquiry',
            NewContactIntent::Other => 'Inquiry — manual review',
        };
    }
}
