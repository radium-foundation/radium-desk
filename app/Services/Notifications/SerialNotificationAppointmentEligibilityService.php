<?php

namespace App\Services\Notifications;

use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\User;
use App\Services\Automation\CustomerWaitingEngagementService;
use Illuminate\Http\Request;

class SerialNotificationAppointmentEligibilityService
{
    public const SKIP_REASON = 'Active support appointment scheduled; serial notification skipped.';

    public function __construct(
        private readonly CustomerWaitingEngagementService $engagementService,
        private readonly NotificationAuditTrailService $auditTrail,
    ) {}

    public function shouldSkip(Incident $incident): bool
    {
        $incident->loadMissing('supportAppointments');

        return $this->engagementService->hasActiveSupportAppointment($incident);
    }

    public function skipReason(): string
    {
        return self::SKIP_REASON;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordSkip(
        Incident $incident,
        NotificationType $type,
        ?User $actor = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        $incident->loadMissing('order');

        $this->auditTrail->recordSkipped(
            new NotificationMessage(
                type: $type,
                customer: $incident->order ?? $incident,
                incident: $incident,
                metadata: $metadata,
                actor: $actor,
                httpRequest: $request,
            ),
            self::SKIP_REASON,
        );
    }
}
