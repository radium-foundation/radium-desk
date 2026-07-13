<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;

class CommunicationActionLifecycleService
{
    public function __construct(
        private readonly CommunicationActionLifecycleAuditService $auditService,
        private readonly CommunicationActionRegistry $registry,
        private readonly CommunicationActionEligibilityService $eligibilityService,
    ) {}

    public function recordOpened(
        Incident $incident,
        User $actor,
        string $actionKey,
        ?Request $request = null,
    ): AuditLog {
        $definition = $this->registry->get($actionKey);

        return $this->auditService->record(
            status: CommunicationActionLifecycleStatus::Opened,
            incident: $incident,
            actionKey: $actionKey,
            actor: $actor,
            executionMode: $definition->executionMode,
            actionLabel: $definition->timelineLabel,
            request: $request,
        );
    }

    /**
     * @param  list<string>  $channels
     */
    public function recordSent(
        Incident $incident,
        User $actor,
        string $actionKey,
        array $channels,
        ?Request $request = null,
    ): AuditLog {
        $definition = $this->registry->get($actionKey);

        return $this->auditService->record(
            status: CommunicationActionLifecycleStatus::Sent,
            incident: $incident,
            actionKey: $actionKey,
            actor: $actor,
            executionMode: $definition->executionMode,
            channels: $channels,
            actionLabel: $definition->timelineLabel,
            request: $request,
        );
    }

    public function recordSkipped(
        Incident $incident,
        User $actor,
        string $actionKey,
        ?string $skipReason = null,
        ?Request $request = null,
    ): AuditLog {
        $definition = $this->registry->get($actionKey);

        return $this->auditService->record(
            status: CommunicationActionLifecycleStatus::Skipped,
            incident: $incident,
            actionKey: $actionKey,
            actor: $actor,
            executionMode: $definition->executionMode,
            actionLabel: $definition->timelineLabel,
            skipReason: $skipReason,
            request: $request,
        );
    }

    public function recordCompleted(
        Incident $incident,
        User $actor,
        string $actionKey,
        ?Request $request = null,
    ): AuditLog {
        $definition = $this->registry->get($actionKey);

        return $this->auditService->record(
            status: CommunicationActionLifecycleStatus::Completed,
            incident: $incident,
            actionKey: $actionKey,
            actor: $actor,
            executionMode: $definition->executionMode,
            actionLabel: $definition->timelineLabel,
            request: $request,
        );
    }

    /**
     * Records a successful send followed by lifecycle completion.
     *
     * @param  list<string>  $channels
     * @return array{sent: AuditLog, completed: AuditLog}
     */
    public function recordSuccessfulExecution(
        Incident $incident,
        User $actor,
        string $actionKey,
        array $channels,
        ?Request $request = null,
    ): array {
        $sent = $this->recordSent($incident, $actor, $actionKey, $channels, $request);
        $completed = $this->recordCompleted($incident, $actor, $actionKey, $request);

        return [
            'sent' => $sent,
            'completed' => $completed,
        ];
    }

    /**
     * @param  list<string>  $channels
     * @return array{sent: AuditLog, completed: AuditLog}
     */
    public function recordSuccessfulExecutionFromContext(
        CommunicationActionExecutionContext $context,
        array $channels,
        ?Request $request = null,
    ): array {
        $sent = $this->auditService->recordFromContext(
            status: CommunicationActionLifecycleStatus::Sent,
            context: $context,
            channels: $channels,
            request: $request,
        );

        $completed = $this->auditService->recordFromContext(
            status: CommunicationActionLifecycleStatus::Completed,
            context: $context,
            request: $request,
        );

        return [
            'sent' => $sent,
            'completed' => $completed,
        ];
    }

    public function recordOpenedFromContext(
        CommunicationActionExecutionContext $context,
        ?Request $request = null,
    ): AuditLog {
        return $this->auditService->recordFromContext(
            status: CommunicationActionLifecycleStatus::Opened,
            context: $context,
            request: $request,
        );
    }

    public function resolveStatus(
        Incident $incident,
        string $actionKey,
        ?User $user = null,
    ): CommunicationActionLifecycleStatus {
        $latestEvent = $this->latestLifecycleEvent($incident, $actionKey);

        if ($latestEvent === null) {
            return $this->resolveAvailableStatus($incident, $actionKey, $user);
        }

        $status = CommunicationActionLifecycleStatus::tryFrom(
            (string) ($latestEvent->new_values['status'] ?? ''),
        );

        if ($status === null) {
            return $this->resolveAvailableStatus($incident, $actionKey, $user);
        }

        if ($status === CommunicationActionLifecycleStatus::Completed) {
            return $this->resolveAvailableStatus($incident, $actionKey, $user);
        }

        return $status;
    }

    public function latestLifecycleEvent(Incident $incident, string $actionKey): ?AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->where('new_values->action_key', $actionKey)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function resolveAvailableStatus(
        Incident $incident,
        string $actionKey,
        ?User $user,
    ): CommunicationActionLifecycleStatus {
        if (! $this->registry->has($actionKey)) {
            return CommunicationActionLifecycleStatus::Available;
        }

        $definition = $this->registry->get($actionKey);

        if (! $this->eligibilityService->canShowAction($definition, $incident, $user)) {
            return CommunicationActionLifecycleStatus::Available;
        }

        return CommunicationActionLifecycleStatus::Available;
    }
}
