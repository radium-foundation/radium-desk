<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\SerialValidation\SerialValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSerialService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SerialValidationService $serialValidationService,
        private readonly ServiceCaseAssignmentEligibilityService $assignmentEligibilityService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {}

    public function assignSerialNumber(Order $order, string $serialNumber, User $actor): Order
    {
        if ($order->isSerialLocked()) {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial Number has already been locked.',
            ]);
        }

        $originalSerial = strtoupper(trim($serialNumber));

        if ($originalSerial === '') {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial number is required.',
            ]);
        }

        $validation = $this->serialValidationService->assertValidForOrder($originalSerial, $order);
        $serialNumber = $validation->normalizedSerial;

        $serialOwner = Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->first();

        if ($serialOwner !== null) {
            throw ValidationException::withMessages([
                'serial_number' => 'This serial number belongs to a different order.',
            ]);
        }

        return DB::transaction(function () use ($order, $serialNumber, $originalSerial, $validation, $actor): Order {
            $oldValues = [
                'serial_number' => $order->serial_number,
            ];

            $now = now();

            $order->update([
                'serial_number' => $serialNumber,
                'serial_entered_at' => $now,
                'serial_entered_by_user_id' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $freshOrder = $order->fresh(['serialEnterer']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'serial.assigned',
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: [
                    'serial_number' => $freshOrder->serial_number,
                    'serial_entered_at' => $freshOrder->serial_entered_at?->toIso8601String(),
                ],
            );

            if ($validation->corrected) {
                $this->serialValidationService->recordIraCorrection(
                    order: $freshOrder,
                    originalSerial: $originalSerial,
                    correctedSerial: $freshOrder->serial_number,
                    actor: $actor,
                );
            }

            $this->assignmentEligibilityService->evaluateAssignmentEligibility($freshOrder, $actor);

            if ($this->assignmentEligibilityService->passesValidationForOrder($freshOrder)) {
                $this->automationMonitor->recordValidationPassed($freshOrder, $actor);
            }

            return $freshOrder;
        });
    }
}
