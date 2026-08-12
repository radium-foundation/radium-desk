<?php

namespace App\Services\Dashboard;

use App\Data\TeamActivitySalesLeadBacklogCleanupSummary;
use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\Incident;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\BusinessHoldService;
use App\Services\RemarkService;
use App\Services\ServiceCaseStatusService;
use App\Support\Remarks\RemarkSystemSource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TeamActivitySalesLeadBacklogCleanupService
{
    public const REMARK = 'Archived: Team Activity Sales Lead email backlog cleanup (P12-08-011)';

    public const EVENT_ARCHIVED = 'team_activity.sales_lead_backlog_archived';

    public function __construct(
        private readonly DashboardSnapshotStore $dashboardSnapshotStore,
        private readonly BusinessHoldService $businessHoldService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {}

    public function cleanup(User $assignee, bool $dryRun = true): TeamActivitySalesLeadBacklogCleanupSummary
    {
        $candidates = $this->candidates($assignee);
        $teamActivityPending = $this->dashboardSnapshotStore->get()->openIncidents($assignee);

        $wouldClose = 0;
        $casesClosed = 0;
        $skipped = 0;
        /** @var array<string, int> $skipReasons */
        $skipReasons = [];

        foreach ($candidates as $incident) {
            $skipReason = $this->skipReason($incident);

            if ($skipReason !== null) {
                $skipped++;
                $this->recordSkipReason($skipReasons, $skipReason);

                continue;
            }

            if ($dryRun) {
                $wouldClose++;

                continue;
            }

            $failureReason = $this->archiveCase($incident);

            if ($failureReason === null) {
                $casesClosed++;
            } else {
                $skipped++;
                $this->recordSkipReason($skipReasons, $failureReason);
            }
        }

        /** @var list<int> $candidateIds */
        $candidateIds = $candidates->pluck('id')->sort()->values()->all();

        return new TeamActivitySalesLeadBacklogCleanupSummary(
            candidatesFound: $candidates->count(),
            wouldClose: $wouldClose,
            casesClosed: $casesClosed,
            skipped: $skipped,
            excludedFromTeamActivityPending: max(0, $teamActivityPending->count() - $candidates->count()),
            candidateIds: $candidateIds,
            skipReasons: $skipReasons,
            breakdown: $this->breakdown($candidates),
        );
    }

    /**
     * @return Collection<int, Incident>
     */
    public function candidates(User $assignee): Collection
    {
        $pendingIds = $this->dashboardSnapshotStore
            ->get()
            ->openIncidents($assignee)
            ->pluck('id');

        if ($pendingIds->isEmpty()) {
            return collect();
        }

        return Incident::query()
            ->whereIn('id', $pendingIds)
            ->where('assigned_to_user_id', $assignee->id)
            ->where('source', IncidentSource::Email)
            ->where('assignment_origin', AssignmentOrigin::Sales)
            ->whereRaw('LOWER(TRIM(category)) = ?', ['sales lead'])
            ->orderBy('id')
            ->get();
    }

    public function matchesSalesLeadEmailBacklog(Incident $incident): bool
    {
        if ($incident->assigned_to_user_id === null) {
            return false;
        }

        if (strcasecmp(trim((string) $incident->category), 'Sales Lead') !== 0) {
            return false;
        }

        if ($incident->source !== IncidentSource::Email) {
            return false;
        }

        if ($incident->assignment_origin !== AssignmentOrigin::Sales) {
            return false;
        }

        return true;
    }

    public function skipReason(Incident $incident): ?string
    {
        if (! $this->matchesSalesLeadEmailBacklog($incident)) {
            return 'not eligible';
        }

        if ($this->businessHoldService->hasActiveHold($incident)) {
            return 'active business hold';
        }

        return null;
    }

    /**
     * @param  Collection<int, Incident>  $candidates
     * @return array<string, int>
     */
    public function breakdown(Collection $candidates): array
    {
        $counts = [
            'category' => [],
            'source' => [],
            'assignment_origin' => [],
        ];

        foreach ($candidates as $incident) {
            $category = trim((string) $incident->category) ?: '(null)';
            $counts['category'][$category] = ($counts['category'][$category] ?? 0) + 1;
            $counts['source'][$incident->source->value] = ($counts['source'][$incident->source->value] ?? 0) + 1;
            $origin = $incident->assignment_origin?->value ?? '(null)';
            $counts['assignment_origin'][$origin] = ($counts['assignment_origin'][$origin] ?? 0) + 1;
        }

        return $counts;
    }

    private function archiveCase(Incident $incident): ?string
    {
        try {
            $actor = $this->automationIdentity->systemUser();
        } catch (ModelNotFoundException) {
            return 'missing actor';
        }

        try {
            $this->remarkService->createSystemRemarkForRemarkable(
                remarkable: $incident,
                actor: $actor,
                body: self::REMARK,
                systemSource: RemarkSystemSource::TEAM_ACTIVITY_SALES_LEAD_BACKLOG_CLEANUP,
            );

            $this->serviceCaseStatusService->updateStatus(
                incident: $incident,
                status: IncidentStatus::Closed,
                actor: $actor,
                broadcast: false,
            );

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_ARCHIVED,
                auditable: $incident->fresh(),
                oldValues: [
                    'status' => $incident->status->value,
                ],
                newValues: [
                    'status' => IncidentStatus::Closed->value,
                    'resolution_reason' => ServiceCaseCloseExceptionReason::DuplicateServiceCase->value,
                    'resolution_reason_label' => ServiceCaseCloseExceptionReason::DuplicateServiceCase->label(),
                    'cleanup_prompt' => 'P12-08-011',
                ],
            );

            return null;
        } catch (ValidationException) {
            return 'close validation failed';
        } catch (QueryException) {
            return 'database error';
        } catch (\Throwable) {
            return 'archive failed';
        }
    }

    /**
     * @param  array<string, int>  $skipReasons
     */
    private function recordSkipReason(array &$skipReasons, string $reason): void
    {
        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
    }
}
