<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSerialService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function assignSerialNumber(Order $order, string $serialNumber, User $actor): Order
    {
        if ($order->isSerialLocked()) {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial Number has already been locked.',
            ]);
        }

        $serialNumber = strtoupper(trim($serialNumber));

        if ($serialNumber === '') {
            throw ValidationException::withMessages([
                'serial_number' => 'Serial number is required.',
            ]);
        }

        return DB::transaction(function () use ($order, $serialNumber, $actor): Order {
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

            return $freshOrder;
        });
    }
}
