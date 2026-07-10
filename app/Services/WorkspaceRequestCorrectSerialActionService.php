<?php

namespace App\Services;

use App\Data\NotificationMessage;
use App\Data\Workspace\WorkspaceActionResponse;
use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Interakt\RequestCorrectSerialEligibilityService;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SerialValidation\RequestCorrectSerialAuditService;
use App\Services\SerialValidation\SerialInsightService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class WorkspaceRequestCorrectSerialActionService
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly RequestCorrectSerialEligibilityService $eligibilityService,
        private readonly SerialInsightService $serialInsightService,
        private readonly RequestCorrectSerialAuditService $auditService,
        private readonly WorkspaceRefreshPolicy $refreshPolicy,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
        private readonly NotificationChannelAvailabilityService $channelAvailabilityService,
    ) {}

    public function send(
        Incident $incident,
        User $actor,
        WorkspaceRequestContext $requestContext,
        ?Request $request = null,
    ): WorkspaceActionResponse {
        if (! $actor->can('update', $incident)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $reason = $this->eligibilityService->ineligibilityReason($incident);

        if ($reason !== null) {
            return WorkspaceActionResponseBuilder::make('request-correct-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($reason)
                ->withToast($reason, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return WorkspaceActionResponseBuilder::make('request-correct-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure('Service case is not linked to an order.')
                ->withToast('Service case is not linked to an order.', 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $insight = $this->serialInsightService->analyze($order);
        $channels = $this->channelAvailabilityService->forRequestCorrectSerial($order);
        $channelBlockReason = $this->channelAvailabilityService->unavailableReason($channels);

        if ($channelBlockReason !== null) {
            $message = 'Notification failed.'."\n".$channelBlockReason;

            return WorkspaceActionResponseBuilder::make('request-correct-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $dispatchResult = $this->notificationDispatcher->send(
            NotificationType::RequestCorrectSerial,
            new NotificationMessage(
                type: NotificationType::RequestCorrectSerial,
                customer: $order,
                incident: $incident,
                template: WhatsAppTemplate::RequestCorrectSerial->value,
                metadata: [
                    'source' => 'customer360',
                    'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
                    'serial_correction' => [
                        'old_serial' => $order->serial_number,
                        'reason' => $insight->technicalReason,
                        'confidence' => $insight->confidence->value,
                        'insight_status' => $insight->status->value,
                    ],
                ],
                actor: $actor,
                httpRequest: $request,
            ),
        );

        if (! $dispatchResult->success) {
            $message = $this->deliverySummaryFormatter->formatOperatorResult($dispatchResult);

            return WorkspaceActionResponseBuilder::make('request-correct-serial', $incident->id)
                ->forContext($requestContext->context)
                ->failure($message)
                ->withToast($message, 'danger')
                ->withUi(closeWorkspaceHost: false)
                ->build();
        }

        $this->auditService->recordRequestSent(
            incident: $incident,
            order: $order,
            actor: $actor,
            insight: $insight,
            request: $request,
        );

        $effects = $this->refreshPolicy->effectsFor(
            $requestContext->context,
            \App\Enums\WorkspaceComponent::RequestCorrectSerial,
            $incident,
        );

        $message = $this->deliverySummaryFormatter->formatOperatorResult($dispatchResult);
        $toastVariant = $this->resolveToastVariant($dispatchResult);

        return WorkspaceActionResponseBuilder::make('request-correct-serial', $incident->id)
            ->forContext($requestContext->context)
            ->success($message)
            ->withToast($message, $toastVariant)
            ->withUi(closeWorkspaceHost: $effects->closeWorkspaceHost)
            ->withExtensions([
                'refresh_customer360' => $requestContext->context === \App\Enums\WorkspaceContext::Customer,
            ])
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
