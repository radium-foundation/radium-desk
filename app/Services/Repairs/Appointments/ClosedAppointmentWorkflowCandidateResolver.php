<?php

namespace App\Services\Repairs\Appointments;

use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Services\SettingService;
use App\Support\Repair\Contracts\CandidateResolverInterface;
use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ClosedAppointmentWorkflowCandidateResolver implements CandidateResolverInterface
{
    public function __construct(
        private readonly SettingService $settingService,
    ) {}

    public function count(RepairBatchOptions $options): int
    {
        return $this->baseQuery($options)->count();
    }

    public function iterate(RepairBatchOptions $options): Generator
    {
        foreach ($this->baseQuery($options)->cursor() as $appointment) {
            /** @var SupportAppointment $appointment */
            $incident = $appointment->incident;
            if ($incident === null) {
                continue;
            }

            yield new RepairCandidate(
                subject: $incident,
                subjectKey: (string) ($incident->reference_no ?: 'INC-'.$incident->id),
                related: $appointment,
                meta: [
                    'order_id' => $incident->order?->order_id,
                    'preferred_date' => $appointment->preferred_date?->toDateString()
                        ?? (string) $appointment->preferred_date,
                    'preferred_time_slot' => $appointment->preferred_time_slot?->value
                        ?? (string) $appointment->preferred_time_slot,
                ],
            );
        }
    }

    public function classify(RepairCandidate $candidate, RepairBatchOptions $options): RepairClassification
    {
        /** @var Incident $incident */
        $incident = $candidate->subject;
        /** @var SupportAppointment|null $appointment */
        $appointment = $candidate->related;

        if (! $incident instanceof Incident || $incident->status !== IncidentStatus::Closed) {
            return new RepairClassification(
                action: 'skip',
                category: 'not_closed',
                skipReason: 'incident_not_closed',
            );
        }

        if (! $appointment instanceof SupportAppointment
            || $appointment->status !== SupportAppointmentStatus::Scheduled) {
            return new RepairClassification(
                action: 'skip',
                category: 'no_scheduled_appointment',
                skipReason: 'no_scheduled_appointment',
            );
        }

        if ($this->hasNewerActiveCase($incident)) {
            return new RepairClassification(
                action: 'cleanup',
                category: 'superseded_by_newer_case',
                priority: 10,
            );
        }

        $mode = (string) $options->extra('mode', 'auto');
        $todayAction = (string) $options->extra('today_action', 'full');
        $includePast = (bool) $options->extra('include_past', false);

        $preferredDate = Carbon::parse(
            $appointment->preferred_date?->toDateString() ?? (string) $appointment->preferred_date,
            config('app.timezone'),
        )->startOfDay();
        $today = now()->timezone(config('app.timezone'))->startOfDay();

        if ($preferredDate->gt($today)) {
            return $this->actionForMode($mode, 'full', 'future_scheduled', 20);
        }

        if ($preferredDate->equalTo($today)) {
            if ($mode === 'cleanup' || $todayAction === 'cleanup') {
                return new RepairClassification(action: 'cleanup', category: 'today', priority: 30);
            }
            if ($todayAction === 'skip' || $mode === 'skip') {
                return new RepairClassification(
                    action: 'skip',
                    category: 'today',
                    skipReason: 'today_action_skip',
                    priority: 30,
                );
            }

            return new RepairClassification(action: 'full', category: 'today', priority: 30);
        }

        // Past stale
        if (! $includePast && $mode !== 'cleanup') {
            return new RepairClassification(
                action: 'skip',
                category: 'stale_past',
                skipReason: 'past_requires_include_past',
                priority: 90,
            );
        }

        return $this->actionForMode($mode === 'full' ? 'cleanup' : $mode, 'cleanup', 'stale_past', 90);
    }

    private function actionForMode(
        string $mode,
        string $defaultAction,
        string $category,
        int $priority,
    ): RepairClassification {
        return match ($mode) {
            'skip' => new RepairClassification(
                action: 'skip',
                category: $category,
                skipReason: 'mode_skip',
                priority: $priority,
            ),
            'cleanup' => new RepairClassification(
                action: 'cleanup',
                category: $category,
                priority: $priority,
            ),
            'full' => new RepairClassification(
                action: 'full',
                category: $category,
                priority: $priority,
            ),
            default => new RepairClassification(
                action: $defaultAction,
                category: $category,
                priority: $priority,
            ),
        };
    }

    private function hasNewerActiveCase(Incident $incident): bool
    {
        if ($incident->order_id === null) {
            return false;
        }

        return Incident::query()
            ->where('order_id', $incident->order_id)
            ->where('id', '>', $incident->id)
            ->whereIn('status', [
                IncidentStatus::Open->value,
                IncidentStatus::InProgress->value,
                IncidentStatus::AwaitingProductDetails->value,
                IncidentStatus::Resolved->value,
            ])
            ->exists();
    }

    /**
     * @return Builder<SupportAppointment>
     */
    private function baseQuery(RepairBatchOptions $options): Builder
    {
        $query = SupportAppointment::query()
            ->with(['incident.order', 'incident.assignee', 'incident.supportAppointments', 'incident.activeWaitingState'])
            ->where('status', SupportAppointmentStatus::Scheduled)
            ->whereHas('incident', function (Builder $incidentQuery): void {
                $incidentQuery->where('status', IncidentStatus::Closed->value);
            })
            ->orderBy('preferred_date')
            ->orderBy('id');

        if ($options->since !== null) {
            $query->whereDate('preferred_date', '>=', $options->since);
        } elseif (! (bool) $options->extra('include_past', false)) {
            $query->whereDate('preferred_date', '>=', now()->timezone(config('app.timezone'))->toDateString());
        }

        if ($options->until !== null) {
            $query->whereDate('preferred_date', '<=', $options->until);
        }

        if ($orderId = $options->extra('order')) {
            $query->whereHas('incident.order', fn (Builder $q) => $q->where('order_id', $orderId));
        }

        if ($incidentRef = $options->extra('incident')) {
            $query->whereHas('incident', function (Builder $q) use ($incidentRef): void {
                if (is_numeric($incidentRef)) {
                    $q->where('id', (int) $incidentRef);
                } else {
                    $q->where('reference_no', $incidentRef);
                }
            });
        }

        if ($appointmentId = $options->extra('appointment')) {
            $query->where('id', (int) $appointmentId);
        }

        if ((bool) $options->extra('shift_admin_only', false)) {
            $adminIds = array_values(array_filter([
                $this->settingService->getInt('assignment.day_shift_admin_user_id'),
                $this->settingService->getInt('assignment.night_shift_admin_user_id'),
            ]));

            $query->whereHas('incident', function (Builder $q) use ($adminIds): void {
                $q->whereIn('assigned_to_user_id', $adminIds);
            });
        }

        return $query;
    }
}
