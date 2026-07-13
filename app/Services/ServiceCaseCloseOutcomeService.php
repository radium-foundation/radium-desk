<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use Illuminate\Support\Carbon;

class ServiceCaseCloseOutcomeService
{
    /**
     * @param  array{
     *     reason_for_closing: \App\Enums\ServiceCaseCloseReasonForClosing,
     *     resolution_type: \App\Enums\ServiceCaseCloseResolutionType|null,
     *     metadata: array<string, mixed>,
     *     closing_summary: string,
     *     notification_preference: \App\Enums\ServiceCaseCloseNotificationPreference,
     * }  $outcomeData
     */
    public function store(
        Incident $incident,
        User $actor,
        array $outcomeData,
        ?Carbon $closedAt = null,
    ): ServiceCaseCloseOutcome {
        return ServiceCaseCloseOutcome::query()->create([
            'incident_id' => $incident->id,
            'reason_for_closing' => $outcomeData['reason_for_closing'],
            'resolution_type' => $outcomeData['resolution_type'],
            'metadata' => $outcomeData['metadata'] !== [] ? $outcomeData['metadata'] : null,
            'closing_summary' => $outcomeData['closing_summary'],
            'notification_preference' => $outcomeData['notification_preference'],
            'closed_by' => $actor->id,
            'closed_at' => $closedAt ?? now(),
        ]);
    }
}
