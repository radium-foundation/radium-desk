<?php

namespace App\Services;

use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprovalNumberService
{
    public const EVENT_SUBMITTED = 'approval_numbers.submitted';

    public const EVENT_DELETED = 'approval_numbers.deleted';

    public function __construct(
        private readonly ApprovalReferenceService $referenceService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function create(User $user, ?string $description, Request $request): ApprovalNumber
    {
        return $this->submit($user, [['description' => $description]], $request)[0];
    }

    /**
     * Persist one or more approval numbers as a single business submission.
     *
     * @param  list<array{description?: ?string}>  $items
     * @return list<ApprovalNumber>
     */
    public function submit(User $user, array $items, Request $request): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('At least one approval number is required.');
        }

        $approvals = DB::transaction(function () use ($user, $items, $request): array {
            $created = [];

            foreach ($items as $item) {
                $description = is_array($item) ? ($item['description'] ?? null) : null;

                $approval = ApprovalNumber::query()->create([
                    'approval_number' => $this->referenceService->generate(),
                    'description' => $description,
                    'created_by' => $user->id,
                ]);

                $this->auditLogService->log(
                    userId: $user->id,
                    event: 'created',
                    auditable: $approval,
                    newValues: [
                        'approval_number' => $approval->approval_number,
                        'description' => $approval->description,
                    ],
                    request: $request,
                );

                $created[] = $approval;
            }

            return $created;
        });

        $this->auditLogService->log(
            userId: $user->id,
            event: self::EVENT_SUBMITTED,
            auditable: $approvals[0],
            newValues: [
                'submission_id' => (string) Str::uuid(),
                'count' => count($approvals),
                'approval_ids' => array_map(
                    static fn (ApprovalNumber $approval): int => (int) $approval->id,
                    $approvals,
                ),
                'approval_numbers' => array_map(
                    static fn (ApprovalNumber $approval): string => (string) $approval->approval_number,
                    $approvals,
                ),
            ],
            request: $request,
        );

        return $approvals;
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function linkIncidents(ApprovalNumber $approval, array $incidentIds, User $user, Request $request): int
    {
        return DB::transaction(function () use ($approval, $incidentIds, $user, $request): int {
            $approval->loadCount('incidents');

            $existingIds = $approval->incidents()
                ->pluck('incidents.id')
                ->all();

            $newIds = array_values(array_diff(array_unique($incidentIds), $existingIds));

            if ($newIds === []) {
                return 0;
            }

            $remainingSlots = ApprovalNumber::MAX_INCIDENTS - $approval->incidents_count;

            if (count($newIds) > $remainingSlots) {
                throw ValidationException::withMessages([
                    'incident_ids' => sprintf(
                        'This approval number can only accept %d more incident(s). Maximum is %d per approval.',
                        max($remainingSlots, 0),
                        ApprovalNumber::MAX_INCIDENTS,
                    ),
                ]);
            }

            $incidents = Incident::query()
                ->whereIn('id', $newIds)
                ->get(['id', 'reference_no']);

            if ($incidents->count() !== count($newIds)) {
                throw ValidationException::withMessages([
                    'incident_ids' => 'One or more selected incidents could not be found.',
                ]);
            }

            $attachData = [];

            foreach ($incidents as $incident) {
                $attachData[$incident->id] = ['linked_by' => $user->id];
            }

            $approval->incidents()->attach($attachData);

            foreach ($incidents as $incident) {
                $this->auditLogService->log(
                    userId: $user->id,
                    event: 'incident_linked',
                    auditable: $approval,
                    newValues: [
                        'approval_number' => $approval->approval_number,
                        'incident_id' => $incident->id,
                        'reference_no' => $incident->reference_no,
                    ],
                    request: $request,
                );
            }

            return $incidents->count();
        });
    }

    public function unlinkIncident(
        ApprovalNumber $approval,
        Incident $incident,
        User $user,
        Request $request,
    ): void {
        DB::transaction(function () use ($approval, $incident, $user, $request): void {
            $isLinked = $approval->incidents()
                ->where('incidents.id', $incident->id)
                ->exists();

            if (! $isLinked) {
                throw ValidationException::withMessages([
                    'incident' => 'This incident is not linked to the approval number.',
                ]);
            }

            $approval->incidents()->detach($incident->id);

            $this->auditLogService->log(
                userId: $user->id,
                event: 'incident_unlinked',
                auditable: $approval,
                oldValues: [
                    'approval_number' => $approval->approval_number,
                    'incident_id' => $incident->id,
                    'reference_no' => $incident->reference_no,
                ],
                request: $request,
            );
        });
    }

    public function delete(ApprovalNumber $approval, User $user, Request $request): void
    {
        DB::transaction(function () use ($approval, $user, $request): void {
            $this->auditLogService->log(
                userId: $user->id,
                event: 'deleted',
                auditable: $approval,
                oldValues: [
                    'approval_number' => $approval->approval_number,
                    'description' => $approval->description,
                    'linked_incidents_count' => $approval->incidents()->count(),
                ],
                request: $request,
            );

            $this->auditLogService->log(
                userId: $user->id,
                event: self::EVENT_DELETED,
                auditable: $approval,
                oldValues: [
                    'approval_number' => $approval->approval_number,
                    'description' => $approval->description,
                    'linked_incidents_count' => $approval->incidents()->count(),
                ],
                request: $request,
            );

            $approval->delete();
        });
    }
}
