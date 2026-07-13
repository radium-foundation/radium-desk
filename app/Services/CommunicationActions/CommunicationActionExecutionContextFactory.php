<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionTriggerSource;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\User;

class CommunicationActionExecutionContextFactory
{
    /**
     * @param  array<string, mixed>  $operatorInput
     * @param  list<string>|null  $selectedChannelValues
     */
    public function forWorkspaceExecution(
        CommunicationActionDefinition $action,
        Incident $incident,
        User $operator,
        WorkspaceContext $workspaceContext,
        array $operatorInput = [],
        ?array $selectedChannelValues = null,
        array $metadata = [],
    ): CommunicationActionExecutionContext {
        return CommunicationActionExecutionContext::initial(
            action: $action,
            incident: $incident,
            operator: $operator,
            executionMode: $action->executionMode,
            triggerSource: CommunicationActionTriggerSource::fromWorkspaceContext($workspaceContext),
            operatorInput: $operatorInput,
            selectedChannelValues: $selectedChannelValues,
            metadata: array_merge($metadata, [
                'workspace_context' => $workspaceContext->value,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $operatorInput
     * @param  list<string>|null  $selectedChannelValues
     */
    public function forAutomation(
        CommunicationActionDefinition $action,
        Incident $incident,
        CommunicationActionExecutionMode $executionMode = CommunicationActionExecutionMode::Automatic,
        ?User $operator = null,
        array $operatorInput = [],
        ?array $selectedChannelValues = null,
        array $metadata = [],
    ): CommunicationActionExecutionContext {
        return CommunicationActionExecutionContext::initial(
            action: $action,
            incident: $incident,
            operator: $operator,
            executionMode: $executionMode,
            triggerSource: CommunicationActionTriggerSource::Automation,
            operatorInput: $operatorInput,
            selectedChannelValues: $selectedChannelValues,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $operatorInput
     * @param  list<string>|null  $selectedChannelValues
     */
    public function forIra(
        CommunicationActionDefinition $action,
        Incident $incident,
        ?User $operator = null,
        array $operatorInput = [],
        ?array $selectedChannelValues = null,
        array $metadata = [],
    ): CommunicationActionExecutionContext {
        return CommunicationActionExecutionContext::initial(
            action: $action,
            incident: $incident,
            operator: $operator,
            executionMode: CommunicationActionExecutionMode::SemiAutomatic,
            triggerSource: CommunicationActionTriggerSource::Ira,
            operatorInput: $operatorInput,
            selectedChannelValues: $selectedChannelValues,
            metadata: $metadata,
        );
    }
}
