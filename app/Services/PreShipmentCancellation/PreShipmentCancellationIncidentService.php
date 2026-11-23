<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\Assignment\HardwareOrderServiceCaseAssignmentCoordinator;
use App\Services\AuditLogService;
use App\Services\ServiceCaseStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreShipmentCancellationIncidentService
{
    public const AUDIT_HARDWARE_PRESHIP_CANCELLED = 'service_case.hardware_preship_cancelled';

    public function __construct(
        private readonly ServiceCaseStatusService $serviceCaseStatus,
        private readonly AuditLogService $auditLogService,
        private readonly HardwareOrderServiceCaseAssignmentCoordinator $hardwareCoordinator,
    ) {}

    public function closeForCancellation(PreShipmentCancellationRequest $request, User $actor): PreShipmentCancellationRequest
    {
        return DB::transaction(function () use ($request, $actor): PreShipmentCancellationRequest {
            $request = PreShipmentCancellationRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($request->service_case_sync_status, [
                PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
                PreShipmentCancellationServiceCaseSyncStatus::NotApplicable,
            ], true)) {
                return $request;
            }

            return $this->closeForCancellationWithinTransaction($request, $actor);
        });
    }

    private function closeForCancellationWithinTransaction(
        PreShipmentCancellationRequest $request,
        User $actor,
    ): PreShipmentCancellationRequest {
        $order = $request->order ?? Order::query()->find($request->order_id);
        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => 'Support order is missing for this cancellation case.',
            ]);
        }

        if (! $this->hardwareCoordinator->isAwaitingInternalSerialAllocation($order)) {
            $request->forceFill([
                'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::NotApplicable,
            ])->save();

            return $request->fresh() ?? $request;
        }

        $open = Incident::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', IncidentStatus::Closed)
            ->orderBy('id')
            ->get();

        if ($open->count() !== 1) {
            $request->forceFill([
                'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Failed,
                'status' => PreShipmentCancellationStatus::HeldServiceCase,
                'sub_status' => $open->count() === 0 ? 'no_open_incident' : 'multiple_open_incidents',
            ])->save();

            return $request->fresh() ?? $request;
        }

        /** @var Incident $incident */
        $incident = $open->first();

        try {
            $incident = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($incident->status === IncidentStatus::Closed) {
                $request->forceFill([
                    'service_case_incident_id' => $incident->id,
                    'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
                ])->save();

                return $request->fresh() ?? $request;
            }

            $closed = $this->serviceCaseStatus->updateStatus(
                incident: $incident,
                status: IncidentStatus::Closed,
                actor: $actor,
                broadcast: false,
                issueStatutoryInvoice: false,
            );

            if (! $this->preshipCancelAuditExists($request->id)) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: self::AUDIT_HARDWARE_PRESHIP_CANCELLED,
                    auditable: $closed,
                    oldValues: [],
                    newValues: [
                        'pre_shipment_cancellation_request_id' => $request->id,
                        'reason' => $request->cancellation_reason,
                        'source_id' => $request->source_id,
                        'incident_id' => $closed->id,
                        'committed_at' => optional($request->committed_at)?->toIso8601String(),
                    ],
                );
            }

            $request->forceFill([
                'service_case_incident_id' => $closed->id,
                'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Succeeded,
                'service_case_outcome' => [
                    'incident_id' => $closed->id,
                    'status' => $closed->status->value,
                    'closed_at' => now()->toIso8601String(),
                ],
            ])->save();

            return $request->fresh() ?? $request;
        } catch (\Throwable) {
            $request->forceFill([
                'service_case_incident_id' => $incident->id,
                'service_case_sync_status' => PreShipmentCancellationServiceCaseSyncStatus::Failed,
                'status' => PreShipmentCancellationStatus::HeldServiceCase,
            ])->save();

            return $request->fresh() ?? $request;
        }
    }

    public function retryClose(PreShipmentCancellationRequest $request, User $actor): PreShipmentCancellationRequest
    {
        return $this->closeForCancellation($request, $actor);
    }

    private function preshipCancelAuditExists(int $preShipmentCancellationRequestId): bool
    {
        return AuditLog::query()
            ->where('event', self::AUDIT_HARDWARE_PRESHIP_CANCELLED)
            ->where('new_values->pre_shipment_cancellation_request_id', $preShipmentCancellationRequestId)
            ->exists();
    }
}
