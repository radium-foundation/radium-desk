<?php

namespace App\Services;

use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderDeviceModelService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly DashboardService $dashboardService,
    ) {}

    public function assignDeviceModel(Order $order, DeviceModel $deviceModel, User $actor, bool $isBulk = false): Order
    {
        if (! $deviceModel->is_active) {
            throw ValidationException::withMessages([
                'device_model_id' => 'This device model is not active.',
            ]);
        }

        return DB::transaction(function () use ($order, $deviceModel, $actor, $isBulk): Order {
            $oldValues = [
                'device_model_id' => $order->device_model_id,
                'device_model' => $order->device_model,
                'product_name' => $order->product_name,
            ];

            $now = now();

            $order->update([
                'device_model_id' => $deviceModel->id,
                'device_model' => $deviceModel->name,
                'product_name' => $order->product_name ?? $deviceModel->name,
                'device_model_assigned_at' => $now,
                'device_model_assigned_by_user_id' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $freshOrder = $order->fresh(['deviceModel', 'deviceModelAssigner']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: $isBulk ? 'device-model.bulk-assigned' : 'device-model.assigned',
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: [
                    'device_model_id' => $freshOrder->device_model_id,
                    'device_model' => $freshOrder->device_model,
                    'device_model_assigned_at' => $freshOrder->device_model_assigned_at?->toIso8601String(),
                ],
            );

            return $freshOrder;
        });
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array{
     *     count: int,
     *     device_model_id: int,
     *     device_model_name: string,
     *     rows: array<int, array{incident_id: int, html: string}>,
     *     succeeded_incident_ids: list<int>,
     *     failed_incidents: list<array{incident_id: int, message: string}>
     * }
     */
    public function assignDeviceModelToIncidents(array $incidentIds, int $deviceModelId, User $actor): array
    {
        $deviceModel = DeviceModel::query()->find($deviceModelId);

        if ($deviceModel === null) {
            throw ValidationException::withMessages([
                'device_model_id' => 'The selected device model is invalid.',
            ]);
        }

        if (! $deviceModel->is_active) {
            throw ValidationException::withMessages([
                'device_model_id' => 'This device model is not active.',
            ]);
        }

        $incidents = Incident::query()
            ->with(['order.deviceModel', 'order.deviceModelAssigner', 'order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get();

        $ordersToUpdate = $incidents
            ->filter(fn (Incident $incident): bool => $incident->order !== null
                && ! $incident->order->hasDeviceModelAssigned()
                && $actor->can('assignDeviceModel', $incident->order))
            ->pluck('order.id')
            ->unique()
            ->values();

        foreach ($ordersToUpdate as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null) {
                continue;
            }

            $this->assignDeviceModel($order, $deviceModel, $actor, isBulk: true);
        }

        $refreshedIncidents = Incident::query()
            ->with(['order.deviceModel', 'order.deviceModelAssigner', 'order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                continue;
            }

            $rows[] = [
                'incident_id' => $incident->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->dashboardService->serviceCaseRowViewData($incident, $actor),
                )->render(),
            ];
        }

        $succeededIncidentIds = [];
        $failedIncidents = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'Service case not found.',
                ];

                continue;
            }

            if ($incident->order !== null
                && $incident->order->device_model_id === $deviceModel->id) {
                $succeededIncidentIds[] = $incidentId;

                continue;
            }

            if ($incident->order === null) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This service case has no order.',
                ];

                continue;
            }

            if ($incident->order->hasDeviceModelAssigned()) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This order already has a device model assigned.',
                ];

                continue;
            }

            if (! $actor->can('assignDeviceModel', $incident->order)) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This action is unauthorized.',
                ];

                continue;
            }

            $failedIncidents[] = [
                'incident_id' => $incidentId,
                'message' => 'Unable to assign device model.',
            ];
        }

        return [
            'count' => count($succeededIncidentIds),
            'device_model_id' => $deviceModel->id,
            'device_model_name' => $deviceModel->name,
            'rows' => $rows,
            'succeeded_incident_ids' => $succeededIncidentIds,
            'failed_incidents' => $failedIncidents,
        ];
    }
}
