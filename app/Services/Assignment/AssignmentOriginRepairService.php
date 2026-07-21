<?php

namespace App\Services\Assignment;

use App\Data\Assignment\AssignmentOriginRepairRow;
use App\Data\Assignment\AssignmentOriginRepairSummary;
use App\Enums\AssignmentOrigin;
use App\Models\AuditLog;
use App\Models\Incident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignmentOriginRepairService
{
    /** @var list<string> */
    private const ESTABLISHING_EVENTS = [
        'service_case.assigned',
        'service_case.reassigned',
        'service_case.escalated',
        'service_case.deferred_smart_assignment',
    ];

    /** @var list<string> */
    private const MANUAL_OVERRIDE_REASONS = [
        'manual_reassign',
        'refund_rejected',
    ];

    public function repair(bool $dryRun = true): AssignmentOriginRepairSummary
    {
        $scanned = 0;
        $changed = 0;
        $skipped = 0;
        $errors = 0;
        /** @var list<AssignmentOriginRepairRow> $changedRows */
        $changedRows = [];
        /** @var list<array{incident_id: int, reason: string}> $errorRows */
        $errorRows = [];

        Incident::query()
            ->where('assignment_origin', AssignmentOrigin::Auto)
            ->whereNotNull('assigned_to_user_id')
            ->with(['order', 'assignee'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $incidents) use (
                $dryRun,
                &$scanned,
                &$changed,
                &$skipped,
                &$errors,
                &$changedRows,
                &$errorRows,
            ): void {
                foreach ($incidents as $incident) {
                    $scanned++;

                    /** @var Incident $incident */
                    $establishingLog = $this->findEstablishingAuditLog($incident);

                    if ($establishingLog === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $this->isManualEstablishingEvent($establishingLog)) {
                        $skipped++;

                        continue;
                    }

                    $row = new AssignmentOriginRepairRow(
                        incidentId: $incident->id,
                        serviceCase: (string) ($incident->reference_no ?? $incident->display_reference),
                        orderId: $incident->order?->order_id,
                        assigneeName: (string) ($incident->assignee?->name ?? 'Unknown'),
                        oldOrigin: AssignmentOrigin::Auto->value,
                        newOrigin: AssignmentOrigin::Manual->value,
                        matchingAuditEvent: $establishingLog->event,
                        matchingAuditLogId: $establishingLog->id,
                    );

                    if ($dryRun) {
                        $changed++;
                        $changedRows[] = $row;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($incident): void {
                            $locked = Incident::query()
                                ->whereKey($incident->id)
                                ->where('assignment_origin', AssignmentOrigin::Auto)
                                ->lockForUpdate()
                                ->first();

                            if ($locked === null) {
                                return;
                            }

                            $locked->update([
                                'assignment_origin' => AssignmentOrigin::Manual,
                            ]);
                        });

                        $fresh = $incident->fresh();

                        if ($fresh?->assignment_origin === AssignmentOrigin::Manual) {
                            $changed++;
                            $changedRows[] = $row;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        $errors++;
                        $errorRows[] = [
                            'incident_id' => $incident->id,
                            'reason' => $exception->getMessage(),
                        ];
                    }
                }
            });

        return new AssignmentOriginRepairSummary(
            dryRun: $dryRun,
            scanned: $scanned,
            changed: $changed,
            skipped: $skipped,
            errors: $errors,
            changedRows: $changedRows,
            errorDetails: $errorRows,
        );
    }

    public function findEstablishingAuditLog(Incident $incident): ?AuditLog
    {
        if ($incident->assigned_to_user_id === null) {
            return null;
        }

        $assigneeId = (int) $incident->assigned_to_user_id;

        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->whereIn('event', self::ESTABLISHING_EVENTS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->first(
                fn (AuditLog $log): bool => (int) ($log->new_values['assigned_to_user_id'] ?? 0) === $assigneeId,
            );
    }

    public function isManualEstablishingEvent(AuditLog $log): bool
    {
        if ($log->event === 'service_case.escalated') {
            return true;
        }

        $newValues = $log->new_values ?? [];

        if (($newValues['assignment_origin'] ?? null) === AssignmentOrigin::Manual->value) {
            return true;
        }

        return in_array(
            (string) ($newValues['override_reason'] ?? ''),
            self::MANUAL_OVERRIDE_REASONS,
            true,
        );
    }
}
