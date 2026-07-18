<?php

namespace App\Services\Operations;

use App\Enums\AssignmentOrigin;
use App\Enums\SupportAppointmentStatus;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\AutomationIdentityService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeferredSmartAssignmentService
{
    private const PROCESS_LOCK_KEY = 'deferred_smart_assignment:process_pending_batch';

    public function __construct(
        private readonly SmartAssignmentService $smartAssignmentService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function processPendingBatch(?int $limit = null): int
    {
        if (! config('smart_assignment.enabled', true)
            || ! config('smart_assignment.deferred.enabled', true)) {
            return 0;
        }

        $batchSize = max(1, $limit ?? (int) config('smart_assignment.deferred.batch_size', 5));
        $lock = Cache::lock(self::PROCESS_LOCK_KEY, 55);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $pendingIds = Incident::query()
                ->pendingSmartAssignment()
                ->whereHas('supportAppointments', function ($query): void {
                    $query->where('status', SupportAppointmentStatus::Scheduled);
                })
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            $assigned = 0;

            foreach ($pendingIds as $incidentId) {
                if ($this->processSinglePendingCase((int) $incidentId)) {
                    $assigned++;
                }
            }

            if ($assigned > 0) {
                Log::info('deferred_smart_assignment.batch_completed', [
                    'candidates' => $pendingIds->count(),
                    'assigned' => $assigned,
                ]);
            }

            return $assigned;
        } finally {
            $lock->release();
        }
    }

    private function processSinglePendingCase(int $incidentId): bool
    {
        return DB::transaction(function () use ($incidentId): bool {
            $incident = Incident::query()
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->with(['order', 'supportAppointments', 'assignee'])
                ->first();

            if ($incident === null || ! $incident->isPendingSmartAssignment()) {
                return false;
            }

            // Never overwrite manual (or retained operational) ownership.
            if ($incident->assignment_origin === AssignmentOrigin::Manual
                || $this->assignmentService->hasManualSupportOwnership($incident)
                || $this->assignmentService->shouldRetainOperationalAssignee($incident)) {
                $incident->update([
                    'pending_smart_assignment' => false,
                    'updated_by' => $this->automationIdentity->systemUser()->id,
                ]);

                return false;
            }

            $appointment = $incident->supportAppointments
                ->first(fn (SupportAppointment $candidate): bool => $candidate->isScheduled());

            if ($appointment === null) {
                return false;
            }

            $result = $this->smartAssignmentService->resolveBestAssignee(order: $incident->order);

            if (! $result->isAssigned()) {
                return false;
            }

            $assignee = $result->assignee;
            assert($assignee instanceof User);

            $actor = $this->automationIdentity->systemUser();

            $incident->update([
                'pending_smart_assignment' => false,
                'updated_by' => $actor->id,
            ]);

            $incident = $incident->fresh(['assignee', 'order', 'supportAppointments']);

            $incident = $this->assignmentService->assignWithAuditContext(
                incident: $incident,
                assignee: $assignee,
                actor: $actor,
                auditContext: [
                    'assignment_method' => 'smart',
                    'assignment_reason' => $result->context,
                    'assignment_trigger' => 'deferred_smart_assignment',
                    'appointment_id' => $appointment->id,
                ],
                event: 'service_case.deferred_smart_assignment',
            );

            event(new SupportAppointmentSmartAssigned(
                incident: $incident,
                appointment: $appointment,
                assignee: $assignee,
                result: $result,
            ));

            return true;
        });
    }
}
