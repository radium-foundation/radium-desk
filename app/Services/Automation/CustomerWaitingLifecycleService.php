<?php

namespace App\Services\Automation;

use App\Data\Automation\ActionHandlerResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use App\Services\RemarkService;
use App\Services\ServiceCaseStatusService;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerWaitingLifecycleService
{
    public const EVENT_WAITING_STARTED = 'service_case.customer_waiting_started';

    public const EVENT_AUTO_CLOSED = 'service_case.customer_waiting_auto_closed';

    public const EVENT_LEGACY_CLEANUP_CLOSED = 'service_case.customer_waiting_legacy_cleanup_closed';

    public const LEGACY_CLEANUP_REMARK = <<<'TEXT'
Closed during customer waiting lifecycle migration cleanup.
Customer information was previously requested but response was not received.
Case can be reopened when customer provides required details.
TEXT;

    public const AUTO_CLOSE_REMARK = <<<'TEXT'
Customer information requested.
Follow-up reminder sent.
No response received within 24 hours after reminder.
Closed automatically.
TEXT;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {}

    public function auditWaitingStarted(IncidentWaitingState $waitingState, User $actor): void
    {
        $waitingState->loadMissing('incident');

        $incident = $waitingState->incident;

        if ($incident === null || $waitingState->started_at === null) {
            return;
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_WAITING_STARTED,
            auditable: $incident,
            oldValues: null,
            newValues: [
                'waiting_reason' => $waitingState->waiting_reason->value,
                'waiting_reason_label' => $waitingState->waiting_reason->label(),
                'customer_waiting_since' => $waitingState->started_at->toIso8601String(),
                'reminder_policy_key' => $waitingState->reminder_policy_key,
            ],
        );
    }

    public function recordFollowupSent(IncidentWaitingState $waitingState, ?Carbon $sentAt = null): void
    {
        if ($waitingState->customer_followup_sent_at !== null) {
            return;
        }

        $waitingState->update([
            'customer_followup_sent_at' => $sentAt ?? Carbon::now(),
            'updated_by' => $this->automationIdentity->systemUser()->id,
        ]);
    }

    public static function autoCloseCutoffAt(Carbon $followupSentAt): Carbon
    {
        $timezone = AppDateFormatter::timezone();
        $sentAt = $followupSentAt->copy()->timezone($timezone);
        [$hour, $minute] = self::businessCutoffParts();
        $sameDayCutoff = $sentAt->copy()->startOfDay()->setTime($hour, $minute);

        if ($sentAt->lt($sameDayCutoff)) {
            return $sameDayCutoff;
        }

        return $sameDayCutoff->addDay();
    }

    public static function isAutoCloseCutoffReached(
        Carbon $followupSentAt,
        ?Carbon $referenceAt = null,
    ): bool {
        $referenceAt ??= Carbon::now();

        return $referenceAt
            ->copy()
            ->timezone(AppDateFormatter::timezone())
            ->gte(self::autoCloseCutoffAt($followupSentAt));
    }

    public function autoCloseForNoResponse(PlannedAutomationAction $action): ActionHandlerResult
    {
        $waitingState = $action->waitingState->fresh(['incident.order']);

        if ($waitingState === null || ! $waitingState->isActive()) {
            return ActionHandlerResult::failure('Waiting state is no longer active.');
        }

        if ($waitingState->reminder_policy_key !== 'customer_waiting_default') {
            return ActionHandlerResult::failure('Auto-close is only supported for customer waiting policies.');
        }

        if ($waitingState->customer_followup_sent_at === null) {
            return ActionHandlerResult::failure('Follow-up reminder has not been sent yet.');
        }

        $followupSentAt = $waitingState->customer_followup_sent_at;

        if (! self::isAutoCloseCutoffReached($followupSentAt)) {
            return ActionHandlerResult::failure('Business cutoff has not been reached.');
        }

        $incident = $waitingState->incident;

        if ($incident === null) {
            return ActionHandlerResult::failure('Incident context is required for auto-close.');
        }

        if ($incident->status === IncidentStatus::Closed) {
            return ActionHandlerResult::failure('Service case is already closed.');
        }

        $actor = $this->automationIdentity->systemUser();

        return DB::transaction(function () use ($waitingState, $incident, $actor, $followupSentAt): ActionHandlerResult {
            $this->remarkService->createForRemarkable(
                remarkable: $incident,
                actor: $actor,
                body: self::AUTO_CLOSE_REMARK,
            );

            $this->serviceCaseStatusService->updateStatus($incident, IncidentStatus::Closed, $actor);

            $this->waitingStateService->clear($incident, $actor);

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::EVENT_AUTO_CLOSED,
                auditable: $incident->fresh(),
                oldValues: [
                    'status' => $incident->status->value,
                    'waiting_reason' => $waitingState->waiting_reason->value,
                ],
                newValues: [
                    'status' => IncidentStatus::Closed->value,
                    'resolution_reason' => ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
                    'resolution_reason_label' => ServiceCaseCloseExceptionReason::CustomerNotResponding->label(),
                    'customer_waiting_since' => $waitingState->started_at?->toIso8601String(),
                    'customer_followup_sent_at' => $followupSentAt->toIso8601String(),
                    'waiting_reason' => $waitingState->waiting_reason->value,
                    'waiting_reason_label' => $waitingState->waiting_reason->label(),
                ],
            );

            return ActionHandlerResult::success(
                externalId: 'customer-waiting-auto-closed',
                metadata: [
                    'resolution_reason' => ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
                    'customer_followup_sent_at' => $followupSentAt->toIso8601String(),
                ],
            );
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lifecycleHistory(Incident $incident): ?array
    {
        $latestWaitingState = $incident->waitingStates()
            ->orderByDesc('started_at')
            ->first();

        if ($latestWaitingState === null) {
            return null;
        }

        $autoClosedAudit = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->whereIn('event', [self::EVENT_AUTO_CLOSED, self::EVENT_LEGACY_CLEANUP_CLOSED])
            ->latest('created_at')
            ->first();

        return [
            'waiting_reason_label' => $latestWaitingState->waiting_reason->label(),
            'customer_waiting_since' => $latestWaitingState->started_at,
            'customer_followup_sent_at' => $latestWaitingState->customer_followup_sent_at,
            'cleared_at' => $latestWaitingState->cleared_at,
            'auto_closed' => $autoClosedAudit !== null,
            'resolution_reason' => $autoClosedAudit?->new_values['resolution_reason'] ?? null,
            'resolution_reason_label' => $autoClosedAudit?->new_values['resolution_reason_label'] ?? null,
            'auto_closed_at' => $autoClosedAudit?->created_at,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function businessCutoffParts(): array
    {
        $cutoffTime = (string) config('workforce_calendar.default_work_end', '18:00');
        $parts = explode(':', $cutoffTime);

        return [
            (int) ($parts[0] ?? 18),
            (int) ($parts[1] ?? 0),
        ];
    }

    /**
     * @return list<WaitingReason>
     */
    public static function customerInputReasons(): array
    {
        return [
            WaitingReason::SerialNumber,
            WaitingReason::Photos,
            WaitingReason::CustomerApproval,
            WaitingReason::Other,
        ];
    }

    public static function lifecycleDeploymentAt(): Carbon
    {
        return Carbon::parse(
            (string) config('waiting_states.lifecycle_deployment_at', '2026-07-07 00:00:00'),
            AppDateFormatter::timezone(),
        );
    }
}
