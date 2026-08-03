<?php

namespace App\Services\Operations;

use App\Data\Operations\LeaveOperationalImpact;
use App\Enums\AssignmentOrigin;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RefundStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\RefundRequest;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Phase 2A — read-only operational impact for leave review.
 * Does not reassign, mutate ownership, or change leave routing.
 */
class LeaveOperationalImpactService
{
    public function __construct(
        private readonly OperationsQueueClassifier $queueClassifier,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
        private readonly TeamAvailabilityService $availabilityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function forLeaveRequest(LeaveRequest $leaveRequest, ?Carbon $at = null): ?LeaveOperationalImpact
    {
        $employee = $leaveRequest->user;

        if ($employee === null) {
            return null;
        }

        return $this->forUser($employee, $at);
    }

    public function forUser(User $employee, ?Carbon $at = null): LeaveOperationalImpact
    {
        $at ??= now();
        $today = $at->copy()->startOfDay();

        $incidents = Incident::query()
            ->with([
                'order',
                'assignee.roles',
                'supportAppointments',
                'activeWaitingState',
                'activeBusinessHold',
            ])
            ->where('assigned_to_user_id', $employee->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->orderByDesc('id')
            ->get();

        $openCount = $incidents->count();
        $awaitingProductDetails = $incidents
            ->where('status', IncidentStatus::AwaitingProductDetails)
            ->count();

        $readyCount = 0;
        $waitingCount = 0;
        $businessHoldCount = 0;
        $automationOwned = 0;

        foreach ($incidents as $incident) {
            $queue = $this->queueClassifier->classify($incident);

            if ($queue === OperationQueue::WaitingCustomer) {
                $waitingCount++;
            }

            if ($queue === OperationQueue::BusinessHold) {
                $businessHoldCount++;
            }

            if ($this->assignmentService->isVisibleInAdminReadyQueue($incident)) {
                $readyCount++;
            }

            if ($incident->assignment_origin === AssignmentOrigin::Auto) {
                $automationOwned++;
            }
        }

        $scheduledAppointments = $this->scheduledAppointmentsFor($incidents);
        $scheduledCount = $scheduledAppointments->count();
        $todayAppointments = $scheduledAppointments
            ->filter(fn (SupportAppointment $appointment): bool => $appointment->preferred_date?->isSameDay($today) ?? false)
            ->count();

        $refundCount = RefundRequest::query()
            ->where('requested_by', $employee->id)
            ->whereIn('status', [
                RefundStatus::Pending->value,
                RefundStatus::PendingExecution->value,
            ])
            ->count();

        $isEscalationOwner = $this->isEscalationOwner($employee);
        $openShiftCount = WorkSession::query()
            ->where('user_id', $employee->id)
            ->whereNull('logout_at')
            ->count();
        $onScheduledShift = $this->workCalendarService->isOnScheduledShift($employee, $at);

        $attendanceDay = $this->attendanceRegisterService->findDay($employee, $today);
        $attendanceLabel = $attendanceDay?->status?->label()
            ?? ($this->workCalendarService->hasApprovedLeave($employee, $at) ? 'On Leave' : 'No register row');
        $availabilityLabel = $this->availabilityService->statusFor($employee)->label();
        $isPresent = $this->presenceEngine->openSessionFor($employee) !== null;

        $workforceUrl = Route::has('workforce.show') ? route('workforce.show', $employee) : null;
        $readyUrl = Route::has('dashboard') ? route('dashboard', ['queue' => OperationQueue::ActionRequired->value]) : null;
        $appointmentsUrl = Route::has('dashboard') ? route('dashboard', ['queue' => OperationQueue::Scheduled->value]) : null;
        $refundsUrl = Route::has('refunds.index')
            ? route('refunds.index', ['requested_by' => $employee->id, 'status' => RefundStatus::Pending->value])
            : null;

        $sections = [
            $this->section('open_cases', 'Open service cases', $openCount, $workforceUrl),
            $this->section('awaiting_product_details', 'Awaiting Product Details', $awaitingProductDetails, $workforceUrl),
            $this->section('ready_queue', 'Ready Queue', $readyCount, $readyUrl),
            $this->section('waiting_customer', 'Waiting Customer', $waitingCount, Route::has('dashboard')
                ? route('dashboard', ['queue' => OperationQueue::WaitingCustomer->value])
                : null),
            $this->section('scheduled_appointments', 'Scheduled Appointments', $scheduledCount, $appointmentsUrl),
            $this->section('todays_appointments', "Today's appointments", $todayAppointments, $appointmentsUrl),
            $this->section('callbacks', 'Callbacks', $scheduledCount, $appointmentsUrl),
            $this->section('refund_work', 'Refund work', $refundCount, $refundsUrl),
            $this->booleanSection('escalation_ownership', 'Escalation ownership', $isEscalationOwner, $workforceUrl),
            $this->section('automation_ownership', 'Automation ownership', $automationOwned, $workforceUrl),
            $this->section('business_holds', 'Business Holds', $businessHoldCount, Route::has('dashboard')
                ? route('dashboard', ['queue' => OperationQueue::BusinessHold->value])
                : null),
            $this->section(
                'active_shifts',
                'Active shifts',
                $openShiftCount,
                $workforceUrl,
                detail: $onScheduledShift ? 'Inside scheduled window' : 'Outside scheduled window',
            ),
            $this->section(
                'attendance_status',
                'Current attendance status',
                $isPresent ? 1 : 0,
                $workforceUrl,
                displayOverride: $attendanceLabel.' · '.$availabilityLabel,
                severityOverride: $openCount > 0 && ! $isPresent ? 'medium' : ($isPresent ? 'low' : 'none'),
            ),
        ];

        $hasWorkload = $this->hasOperationalWorkload(
            openCount: $openCount,
            readyCount: $readyCount,
            waitingCount: $waitingCount,
            scheduledCount: $scheduledCount,
            refundCount: $refundCount,
            businessHoldCount: $businessHoldCount,
            automationOwned: $automationOwned,
            isEscalationOwner: $isEscalationOwner,
        );

        return new LeaveOperationalImpact(
            userId: $employee->id,
            employeeName: $employee->firstName() ?: $employee->name,
            hasWorkload: $hasWorkload,
            warningMessage: $hasWorkload
                ? 'Approving leave will NOT automatically redistribute this work.'
                : 'No operational workload detected.',
            sections: $sections,
            shortcuts: [
                'open_cases' => $workforceUrl,
                'appointments' => $appointmentsUrl,
                'ready_queue' => $readyUrl,
                'workforce' => $workforceUrl,
                'refunds' => $refundsUrl,
            ],
            attendanceLabel: $attendanceLabel,
            availabilityLabel: $availabilityLabel,
            hasOpenShift: $openShiftCount > 0,
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, SupportAppointment>
     */
    private function scheduledAppointmentsFor(Collection $incidents): Collection
    {
        $incidentIds = $incidents->pluck('id')->all();

        if ($incidentIds === []) {
            return collect();
        }

        return SupportAppointment::query()
            ->whereIn('incident_id', $incidentIds)
            ->where('status', SupportAppointmentStatus::Scheduled)
            ->orderBy('preferred_date')
            ->get();
    }

    private function isEscalationOwner(User $employee): bool
    {
        if ($employee->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)) {
            return true;
        }

        $level1 = strtolower(trim((string) config('service_case_assignment.escalation.level_1_email', '')));

        return $level1 !== '' && strcasecmp((string) $employee->email, $level1) === 0;
    }

    private function hasOperationalWorkload(
        int $openCount,
        int $readyCount,
        int $waitingCount,
        int $scheduledCount,
        int $refundCount,
        int $businessHoldCount,
        int $automationOwned,
        bool $isEscalationOwner,
    ): bool {
        return $openCount > 0
            || $readyCount > 0
            || $waitingCount > 0
            || $scheduledCount > 0
            || $refundCount > 0
            || $businessHoldCount > 0
            || $automationOwned > 0
            || $isEscalationOwner;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     count: int|string,
     *     severity: string,
     *     severity_label: string,
     *     view_url: ?string,
     *     display: string,
     *     detail: ?string
     * }
     */
    private function section(
        string $key,
        string $label,
        int $count,
        ?string $viewUrl,
        ?string $detail = null,
        ?string $displayOverride = null,
        ?string $severityOverride = null,
    ): array {
        $severity = $severityOverride ?? $this->severityForCount($count);

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'severity' => $severity,
            'severity_label' => ucfirst($severity),
            'view_url' => $viewUrl,
            'display' => $displayOverride ?? (string) $count,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     count: int|string,
     *     severity: string,
     *     severity_label: string,
     *     view_url: ?string,
     *     display: string,
     *     detail: ?string
     * }
     */
    private function booleanSection(string $key, string $label, bool $yes, ?string $viewUrl): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $yes ? 1 : 0,
            'severity' => $yes ? 'high' : 'none',
            'severity_label' => $yes ? 'High' : 'None',
            'view_url' => $viewUrl,
            'display' => $yes ? 'YES' : 'No',
            'detail' => null,
        ];
    }

    private function severityForCount(int $count): string
    {
        return match (true) {
            $count <= 0 => 'none',
            $count <= 2 => 'low',
            $count <= 9 => 'medium',
            default => 'high',
        };
    }
}
