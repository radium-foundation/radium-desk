<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Data\NotificationMessage;
use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\NotificationChannelType;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\User;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\WorkspaceActionResponseBuilder;
use App\Services\WorkspaceRefreshPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommunicationActionExecutorService
{
    public function __construct(
        private readonly CommunicationActionRegistry $registry,
        private readonly CommunicationActionEligibilityService $eligibilityService,
        private readonly CommunicationActionAvailabilityService $availabilityService,
        private readonly CommunicationActionVariableResolver $variableResolver,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly CommunicationActionLifecycleService $lifecycleService,
        private readonly CommunicationActionExecutionContextFactory $executionContextFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $operatorInput
     * @param  list<string>|null  $selectedChannels
     */
    public function execute(
        string $actionKey,
        Incident $incident,
        User $actor,
        WorkspaceRequestContext $requestContext,
        array $operatorInput = [],
        ?array $selectedChannels = null,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $actor->can('update', $incident)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $definition = $this->registry->get($actionKey);

        if ($definition->executionMode !== CommunicationActionExecutionMode::Manual) {
            return $this->failureResponse(
                actionKey: $definition->key->value,
                incidentId: $incident->id,
                requestContext: $requestContext,
                message: 'This communication action is not available for manual execution.',
            );
        }

        $ineligibilityReason = $this->eligibilityService->ineligibilityReason($definition, $incident, $actor);

        if ($ineligibilityReason !== null) {
            return $this->failureResponse(
                actionKey: $definition->key->value,
                incidentId: $incident->id,
                requestContext: $requestContext,
                message: $ineligibilityReason,
            );
        }

        try {
            $this->validateOperatorInput($definition, $operatorInput);
        } catch (ValidationException $exception) {
            return $this->failureResponse(
                actionKey: $definition->key->value,
                incidentId: $incident->id,
                requestContext: $requestContext,
                message: collect($exception->errors())->flatten()->first()
                    ?? 'The submitted details are invalid.',
            );
        }

        $incident->loadMissing('order');
        $channelAvailability = $this->availabilityService->forDefinition($definition, $incident->order);

        $executionContext = $this->executionContextFactory->forWorkspaceExecution(
            action: $definition,
            incident: $incident,
            operator: $actor,
            workspaceContext: $requestContext->context,
            operatorInput: $operatorInput,
            selectedChannelValues: $selectedChannels,
        );

        $eligibleChannels = $this->resolveEligibleChannels($definition, $channelAvailability);
        $allowedChannels = $this->resolveAllowedChannels(
            definition: $definition,
            channelAvailability: $channelAvailability,
            selectedChannels: $selectedChannels,
        );

        $executionContext = $executionContext
            ->withEligibleChannels($eligibleChannels)
            ->withSelectedChannels($allowedChannels);

        $channelBlockReason = $this->availabilityService->unavailableReason($channelAvailability);

        if (! $this->availabilityService->hasDeliverableChannel($channelAvailability)) {
            return $this->failureResponse(
                actionKey: $definition->key->value,
                incidentId: $incident->id,
                requestContext: $requestContext,
                message: 'Notification failed.'."\n".$channelBlockReason,
            );
        }

        $executionContext = $executionContext->withResolvedVariables(
            $this->variableResolver->resolveFromContext($executionContext),
        );

        $dispatchResult = $this->notificationDispatcher->send(
            $executionContext->action->notificationType,
            $this->notificationMessageFromContext($executionContext, $request),
            allowedChannels: $executionContext->selectedChannels,
        );

        if (! $dispatchResult->success) {
            $message = $this->deliverySummaryFormatter->formatOperatorResult($dispatchResult);

            return $this->failureResponse(
                actionKey: $definition->key->value,
                incidentId: $incident->id,
                requestContext: $requestContext,
                message: $message,
            );
        }

        $sentChannels = collect($dispatchResult->results)
            ->filter(fn (\App\Data\NotificationResult $result): bool => $result->countsTowardSuccess())
            ->map(fn (\App\Data\NotificationResult $result): string => $result->channel->value)
            ->values()
            ->all();

        $this->lifecycleService->recordSuccessfulExecutionFromContext(
            context: $executionContext,
            channels: $sentChannels,
            request: $request,
        );

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            \App\Enums\WorkspaceComponent::CommunicationAction,
            $incident,
        );

        $message = $this->deliverySummaryFormatter->formatOperatorResult($dispatchResult);

        return WorkspaceActionResponseBuilder::make('communication-action', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, $this->resolveToastVariant($dispatchResult))
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === WorkspaceContext::Customer,
            ])
            ->build();
    }

    /**
     * @param  array<string, mixed>  $operatorInput
     */
    private function validateOperatorInput(
        CommunicationActionDefinition $definition,
        array $operatorInput,
    ): void {
        $rules = [];

        foreach ($definition->variables as $variable) {
            $rules[$variable->key] = [$variable->required ? 'required' : 'nullable', 'string', 'max:255'];
        }

        if ($rules === []) {
            return;
        }

        Validator::make($operatorInput, $rules)->validate();
    }

    /**
     * @param  array<string, array<string, mixed>>  $channelAvailability
     * @return list<NotificationChannelType>
     */
    private function resolveEligibleChannels(
        CommunicationActionDefinition $definition,
        array $channelAvailability,
    ): array {
        return collect($definition->channels)
            ->filter(fn (NotificationChannelType $channel): bool => ($channelAvailability[$channel->value]['available'] ?? false) === true)
            ->values()
            ->all();
    }

    private function notificationMessageFromContext(
        CommunicationActionExecutionContext $context,
        ?Request $request,
    ): NotificationMessage {
        return new NotificationMessage(
            type: $context->action->notificationType,
            customer: $context->customer,
            incident: $context->incident,
            template: $context->action->whatsappTemplate?->value,
            variables: $context->resolvedVariables,
            metadata: $context->toNotificationMetadata(),
            actor: $context->operator,
            httpRequest: $request,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $channelAvailability
     * @param  list<string>|null  $selectedChannels
     * @return list<NotificationChannelType>
     */
    private function resolveAllowedChannels(
        CommunicationActionDefinition $definition,
        array $channelAvailability,
        ?array $selectedChannels,
    ): array {
        $supported = collect($definition->channels);

        if ($selectedChannels !== null && $selectedChannels !== []) {
            $supported = $supported->filter(
                fn (NotificationChannelType $channel): bool => in_array($channel->value, $selectedChannels, true),
            );
        }

        return $supported
            ->filter(fn (NotificationChannelType $channel): bool => ($channelAvailability[$channel->value]['available'] ?? false) === true)
            ->values()
            ->all();
    }

    private function failureResponse(
        string $actionKey,
        int $incidentId,
        WorkspaceRequestContext $requestContext,
        string $message,
    ): WorkspaceActionResponse {
        return WorkspaceActionResponseBuilder::make('communication-action', $incidentId)
            ->forContext($requestContext->context)
            ->failure($message)
            ->withToast($message, 'danger')
            ->withUi(closeWorkspaceHost: false)
            ->build();
    }

    private function resolveToastVariant(\App\Data\NotificationDispatchResult $dispatchResult): string
    {
        $hasFailure = collect($dispatchResult->results)->contains(
            fn (\App\Data\NotificationResult $result): bool => ! $result->isSkipped() && ! $result->success,
        );

        return $hasFailure ? 'warning' : 'success';
    }
}
