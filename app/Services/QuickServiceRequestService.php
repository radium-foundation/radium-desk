<?php

namespace App\Services;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Services\SerialValidation\SerialValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickServiceRequestService
{
    public function __construct(
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly SerialValidationService $serialValidationService,
    ) {}

    public function findByOrderId(string $orderId): ?Order
    {
        return Order::query()
            ->where('order_id', $orderId)
            ->first();
    }

    /**
     * @deprecated Agent-facing intake must use CustomerIntakeService. Reserved for explicit internal callers.
     */
    public function create(
        User $user,
        string $orderId,
        string $serialNumber,
        string $product,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
        bool $allowManualOrderIdentityCreation = false,
    ): Incident {
        if (! $allowManualOrderIdentityCreation) {
            throw ValidationException::withMessages([
                'order_id' => 'Manual order identity creation is disabled. Use customer intake instead.',
            ]);
        }

        if (filled($orderId) && ! Order::isInquiryOrderId($orderId)) {
            throw ValidationException::withMessages([
                'order_id' => 'Manual RD-style order identity creation is no longer permitted.',
            ]);
        }

        return DB::transaction(function () use ($user, $orderId, $serialNumber, $product, $source, $notes, $highPriority): Incident {
            $originalSerial = strtoupper(trim($serialNumber));
            $validation = $this->serialValidationService->assertValid($originalSerial, $product);
            $serialNumber = $validation->normalizedSerial;

            $serialOwner = Order::query()
                ->where('serial_number', $serialNumber)
                ->first();

            if ($serialOwner !== null) {
                throw ValidationException::withMessages([
                    'serial_number' => 'This serial number belongs to a different order.',
                ]);
            }

            $order = Order::query()->create([
                'order_id' => $orderId,
                'serial_number' => $serialNumber,
                'product_name' => $product,
                'device_model' => $product,
                'status' => OrderStatus::Active,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if ($validation->corrected) {
                $this->serialValidationService->recordIraCorrection(
                    order: $order,
                    originalSerial: $originalSerial,
                    correctedSerial: $serialNumber,
                    actor: $user,
                );
            }

            return $this->createForOrder(
                user: $user,
                order: $order,
                source: $source,
                notes: $notes,
                highPriority: $highPriority,
            );
        });
    }

    public function createForOrder(
        User $user,
        Order $order,
        IncidentSource $source,
        ?string $notes,
        bool $highPriority = false,
        ?string $title = null,
    ): Incident {
        $product = $order->product_name ?: $order->device_model ?: 'General';

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $this->incidentReferenceService->generate(),
            'category' => 'General',
            'source' => $source,
            'title' => $title ?? ('Service request — '.$product),
            'description' => $notes ?? '',
            'status' => IncidentStatus::Open,
            'high_priority' => $highPriority,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $incident = $this->serviceCaseAssignmentService->assignOnCreate($incident, $user);

        if ($highPriority && $incident->assignee !== null
            && $incident->assignee->is_active
            && ! $incident->assignee->trashed()
            && app(SettingService::class)->getBool('notifications.high_priority_enabled', true)) {
            $incident->assignee->notify(new HighPriorityServiceCaseNotification($incident, $user));
        }

        $this->dashboardBroadcastService->serviceCaseCreated($incident, $user);

        return $incident;
    }

    public function assertSerialMatchesOrder(Order $order, string $serialNumber): void
    {
        if ($order->serial_number !== $serialNumber) {
            throw ValidationException::withMessages([
                'serial_number' => 'The serial number does not match this order.',
            ]);
        }
    }
}
