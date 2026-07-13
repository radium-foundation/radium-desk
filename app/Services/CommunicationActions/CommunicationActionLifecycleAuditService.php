<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class CommunicationActionLifecycleAuditService
{
    public const EVENT = 'communication_action.lifecycle';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  list<string>  $channels
     */
    public function record(
        CommunicationActionLifecycleStatus $status,
        Incident $incident,
        string $actionKey,
        ?User $actor,
        CommunicationActionExecutionMode $executionMode,
        array $channels = [],
        ?string $actionLabel = null,
        ?string $skipReason = null,
        ?Request $request = null,
    ): AuditLog {
        return $this->auditLogService->log(
            userId: $actor?->id,
            event: self::EVENT,
            auditable: $incident,
            newValues: [
                'action_key' => $actionKey,
                'action_label' => $actionLabel,
                'status' => $status->value,
                'execution_mode' => $executionMode->value,
                'channels' => $channels,
                'skip_reason' => $skipReason,
                'operator_name' => $actor?->name,
            ],
            request: $request,
        );
    }

    public function recordFromContext(
        CommunicationActionLifecycleStatus $status,
        CommunicationActionExecutionContext $context,
        array $channels = [],
        ?string $skipReason = null,
        ?Request $request = null,
    ): AuditLog {
        return $this->record(
            status: $status,
            incident: $context->incident,
            actionKey: $context->actionKey(),
            actor: $context->operator,
            executionMode: $context->executionMode,
            channels: $channels,
            actionLabel: $context->action->timelineLabel,
            skipReason: $skipReason,
            request: $request,
        );
    }
}
