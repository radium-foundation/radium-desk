<?php

namespace App\Services;

use App\Data\CustomerCorrectionData;
use App\Models\CustomerDataCorrection;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerCorrectionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function apply(Order $order, CustomerCorrectionData $data, User $actor): Order
    {
        $changes = $this->buildChanges($order, $data);

        if ($changes === []) {
            throw ValidationException::withMessages([
                'customer_name' => 'No customer fields were changed.',
            ]);
        }

        return DB::transaction(function () use ($order, $data, $actor, $changes): Order {
            $correction = CustomerDataCorrection::query()->create([
                'order_id' => $order->id,
                'corrected_by' => $actor->id,
                'status' => 'applied',
                'reason' => $data->reason,
            ]);

            $oldValues = [];
            $newValues = [];
            $orderUpdates = [];

            foreach ($changes as $fieldName => $change) {
                $correction->items()->create([
                    'field_name' => $fieldName,
                    'old_value' => $change['old'],
                    'new_value' => $change['new'],
                ]);

                $oldValues[$fieldName] = $change['old'];
                $newValues[$fieldName] = $change['new'];
                $orderUpdates[$fieldName] = $change['new'];
            }

            $orderUpdates['updated_by'] = $actor->id;

            $order->update($orderUpdates);

            $freshOrder = $order->fresh();

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'customer.details.corrected',
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: $newValues,
            );

            return $freshOrder;
        });
    }

    /**
     * @return array<string, array{old: ?string, new: ?string}>
     */
    private function buildChanges(Order $order, CustomerCorrectionData $data): array
    {
        $fieldMap = [
            'customer_name' => $data->customerName,
            'customer_phone' => $data->customerPhone,
            'customer_email' => $data->customerEmail,
        ];

        $changes = [];

        foreach ($fieldMap as $fieldName => $newValue) {
            if ($order->{$fieldName} === $newValue) {
                continue;
            }

            $changes[$fieldName] = [
                'old' => $order->{$fieldName},
                'new' => $newValue,
            ];
        }

        return $changes;
    }
}
