<?php

namespace App\Services\Bonvoice;

use App\Enums\BonvoiceCallLinkType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NewContactIntent;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\Order;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\CustomerIntakeService;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\QuickServiceRequestService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseStatusService;
use App\Services\SettingService;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BonvoiceMissedCallRecoveryService
{
    public const CATEGORY = 'Missed Call Recovery';

    public function __construct(
        private readonly BonvoiceInboundCustomerResolver $customerResolver,
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly QuickServiceRequestService $quickServiceRequestService,
        private readonly CustomerIntakeService $customerIntakeService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseStatusService $statusService,
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly SettingService $settingService,
    ) {}

    public function process(BonvoiceCallEvent $event, ?string $previousStatus): void
    {
        if (! config('bonvoice.missed_call_recovery_enabled', false)) {
            return;
        }

        try {
            if (BonvoiceCallStatuses::isInbound($event->direction)) {
                if (BonvoiceCallStatuses::transitionedToMissed($previousStatus, $event->status)) {
                    $this->handleMissedCall($event);
                } elseif (BonvoiceCallStatuses::transitionedToAnswered($previousStatus, $event->status)) {
                    $this->maybeAutoResolveOnAnswered($event);
                }

                return;
            }

            if (BonvoiceCallStatuses::transitionedToAnswered($previousStatus, $event->status)) {
                $this->maybeAutoResolveOnAnswered($event);
            }
        } catch (Throwable $exception) {
            Log::error('[BonVoice Missed Call Recovery] Processing failed', [
                'call_id' => $event->call_id,
                'bonvoice_call_event_id' => $event->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function handleMissedCall(BonvoiceCallEvent $event): void
    {
        if ($this->linkExists($event, BonvoiceCallLinkType::Missed)) {
            return;
        }

        $recoveryPhone = $this->resolveRecoveryPhone($event->customer_phone);

        if ($recoveryPhone === null) {
            return;
        }

        $actor = $this->automationIdentity->systemUser();
        $at = $event->started_at ?? now();

        DB::transaction(function () use ($event, $recoveryPhone, $actor, $at): void {
            $existing = $this->findOpenRecoveryCaseForUpdate($recoveryPhone);

            if ($existing instanceof Incident) {
                $this->mergeMissedCall($existing, $event, $recoveryPhone, $actor, $at);

                return;
            }

            $this->createRecoveryCase($event, $recoveryPhone, $actor, $at);
        });
    }

    private function createRecoveryCase(
        BonvoiceCallEvent $event,
        string $recoveryPhone,
        User $actor,
        Carbon $at,
    ): void {
        $match = $this->customerResolver->resolve($event->customer_phone);
        $notes = $this->missedCallDescription($event);

        if ($match['order_id'] !== null) {
            $order = Order::query()->findOrFail($match['order_id']);
            $incident = $this->quickServiceRequestService->createForOrder(
                user: $actor,
                order: $order,
                source: IncidentSource::Call,
                notes: $notes,
                highPriority: true,
                title: $this->recoveryTitle($recoveryPhone),
                category: self::CATEGORY,
                assignOnCreate: false,
            );
        } else {
            $incident = $this->customerIntakeService->createNewContact(
                user: $actor,
                intent: NewContactIntent::GeneralSupport,
                source: IncidentSource::Call,
                customerName: null,
                phone: $recoveryPhone,
                serialNumber: null,
                product: null,
                notes: $notes,
                highPriority: true,
                assignOnCreate: false,
            );

            $incident->update([
                'category' => self::CATEGORY,
                'title' => $this->recoveryTitle($recoveryPhone),
                'updated_by' => $actor->id,
            ]);
        }

        $incident->update([
            'recovery_phone' => $recoveryPhone,
            'missed_call_attempt_count' => 1,
            'last_missed_at' => $event->started_at ?? $at,
            'updated_by' => $actor->id,
        ]);

        $this->linkCall($incident, $event, BonvoiceCallLinkType::Missed);
        $incident = $this->assignForRecovery($incident->fresh(['assignee', 'order']), $actor, $at);
        $this->notifyHighPriorityIfNeeded($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'missed_call_recovery.created',
            auditable: $incident,
            newValues: [
                'recovery_phone' => $recoveryPhone,
                'call_id' => $event->call_id,
                'missed_call_attempt_count' => 1,
            ],
        );
    }

    private function mergeMissedCall(
        Incident $incident,
        BonvoiceCallEvent $event,
        string $recoveryPhone,
        User $actor,
        Carbon $at,
    ): void {
        $attemptCount = (int) $incident->missed_call_attempt_count + 1;

        $incident->update([
            'missed_call_attempt_count' => $attemptCount,
            'last_missed_at' => $event->started_at ?? $at,
            'high_priority' => true,
            'recovery_phone' => $recoveryPhone,
            'updated_by' => $actor->id,
        ]);

        $this->linkCall($incident, $event, BonvoiceCallLinkType::Missed);

        if ($incident->assigned_to_user_id === null) {
            $incident = $this->assignForRecovery($incident->fresh(['assignee', 'order']), $actor, $at);
            $this->notifyHighPriorityIfNeeded($incident, $actor);
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'missed_call_recovery.merged',
            auditable: $incident->fresh(),
            newValues: [
                'recovery_phone' => $recoveryPhone,
                'call_id' => $event->call_id,
                'missed_call_attempt_count' => $attemptCount,
            ],
        );
    }

    private function maybeAutoResolveOnAnswered(BonvoiceCallEvent $event): void
    {
        if (! BonvoiceCallStatuses::isInbound($event->direction)) {
            return;
        }

        $recoveryPhone = $this->resolveRecoveryPhone($event->customer_phone);

        if ($recoveryPhone === null) {
            return;
        }

        $actor = $this->automationIdentity->systemUser();

        DB::transaction(function () use ($event, $recoveryPhone, $actor): void {
            $cases = Incident::query()
                ->where('category', self::CATEGORY)
                ->where('recovery_phone', $recoveryPhone)
                ->whereIn('status', IncidentStatus::operationallyActive())
                ->lockForUpdate()
                ->get();

            if ($cases->count() !== 1) {
                return;
            }

            /** @var Incident $incident */
            $incident = $cases->first();

            if ($incident->status === IncidentStatus::Resolved) {
                return;
            }

            $this->linkCall($incident, $event, BonvoiceCallLinkType::Answered);

            $resolved = $this->statusService->updateStatus($incident, IncidentStatus::Resolved, $actor);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'missed_call_recovery.auto_resolved',
                auditable: $resolved,
                newValues: [
                    'recovery_phone' => $recoveryPhone,
                    'call_id' => $event->call_id,
                    'reason' => 'customer_returned_call',
                    'status' => IncidentStatus::Resolved->value,
                ],
            );
        });
    }

    private function assignForRecovery(Incident $incident, User $actor, Carbon $at): Incident
    {
        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if ($this->isWithinSupportHours($at)) {
            $incident = $this->assignmentService->assignViaRoundRobinAfterGracePeriod($incident, $actor);

            if ($incident->assigned_to_user_id !== null) {
                return $incident;
            }

            return $this->assignShiftAdminFallback($incident, $actor, $at, 'no_active_support_agents');
        }

        return $this->assignShiftAdminDirect($incident, $actor, $at);
    }

    private function assignShiftAdminDirect(Incident $incident, User $actor, Carbon $at): Incident
    {
        $assignee = $this->assignmentService->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            return $incident;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'missed_call_recovery',
                'assignment_override' => true,
                'override_reason' => 'after_hours_shift_admin',
            ],
        );
    }

    private function assignShiftAdminFallback(
        Incident $incident,
        User $actor,
        Carbon $at,
        string $reason,
    ): Incident {
        $assignee = $this->assignmentService->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            return $incident;
        }

        $assigned = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'missed_call_recovery',
                'assignment_override' => true,
                'override_reason' => 'shift_admin_fallback',
            ],
        );

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'missed_call_recovery.assignment_fallback',
            auditable: $assigned,
            newValues: [
                'reason' => $reason,
                'assigned_to_user_id' => $assigned->assigned_to_user_id,
            ],
        );

        return $assigned;
    }

    private function findOpenRecoveryCaseForUpdate(string $recoveryPhone): ?Incident
    {
        return Incident::query()
            ->where('category', self::CATEGORY)
            ->where('recovery_phone', $recoveryPhone)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();
    }

    private function linkCall(Incident $incident, BonvoiceCallEvent $event, BonvoiceCallLinkType $linkType): void
    {
        try {
            IncidentBonvoiceCallLink::query()->create([
                'incident_id' => $incident->id,
                'bonvoice_call_event_id' => $event->id,
                'call_id' => $event->call_id,
                'link_type' => $linkType,
                'linked_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateLink($exception)) {
                throw $exception;
            }
        }
    }

    private function linkExists(BonvoiceCallEvent $event, BonvoiceCallLinkType $linkType): bool
    {
        return IncidentBonvoiceCallLink::query()
            ->where('call_id', $event->call_id)
            ->where('link_type', $linkType)
            ->exists();
    }

    private function isDuplicateLink(QueryException $exception): bool
    {
        $errorCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($errorCode, ['1062', '19', '2067', '1555'], true);
    }

    private function resolveRecoveryPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $stored = $this->customerMatcher->resolveStoredPhone(phoneNumber: $phone);

        return $stored ?? trim($phone);
    }

    private function isWithinSupportHours(Carbon $at): bool
    {
        $localized = $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
        $time = $localized->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');

        return $time >= $start && $time <= $end;
    }

    private function recoveryTitle(string $recoveryPhone): string
    {
        return 'Missed call recovery — '.$recoveryPhone;
    }

    private function missedCallDescription(BonvoiceCallEvent $event): string
    {
        $startedAt = $event->started_at?->toIso8601String() ?? 'unknown time';

        return sprintf(
            'Automated missed call recovery case. Call ID: %s. Status: %s. Started: %s.',
            $event->call_id,
            $event->status ?? 'unknown',
            $startedAt,
        );
    }

    private function notifyHighPriorityIfNeeded(Incident $incident, User $actor): void
    {
        $incident = $incident->fresh(['assignee']);

        if (! $incident->high_priority
            || $incident->assignee === null
            || ! $incident->assignee->is_active
            || $incident->assignee->trashed()
            || ! $this->settingService->getBool('notifications.high_priority_enabled', true)) {
            return;
        }

        $incident->assignee->notify(new HighPriorityServiceCaseNotification($incident, $actor));
    }
}
