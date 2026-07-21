<?php

namespace App\Services;

use App\Data\Workspace\WorkspaceRequestContext;
use App\Enums\CommunicationActionKey;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplate;
use App\Enums\WorkspaceActionType;
use App\Enums\WorkspaceComponent;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionAvailabilityService;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionTargetProviderRegistry;
use App\Services\CustomerCorrection\CustomerCorrectionEligibilityService;
use App\Services\DeviceModelCorrection\DeviceModelCorrectionEligibilityService;
use App\Services\IdentityCorrection\IdentityCorrectionEligibilityEvaluator;
use App\Services\Inquiry\InquiryOrderLinkEligibilityService;
use App\Services\Interakt\CustomerNotRespondingEligibilityService;
use App\Services\Interakt\InteraktTemplateConfigurationValidator;
use App\Services\Interakt\RequestCorrectSerialCommunicationHistoryService;
use App\Services\Interakt\RequestCorrectSerialEligibilityService;
use App\Services\Interakt\RequestSerialCommunicationHistoryService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\SerialCorrection\SerialCorrectionEligibilityService;
use App\Services\SerialValidation\SerialInsightService;
use App\Services\SerialValidation\SerialValidationService;
use Illuminate\Auth\Access\AuthorizationException;

class WorkspaceComponentService
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
        private readonly RequestSerialNumberEligibilityService $requestSerialEligibilityService,
        private readonly RequestCorrectSerialEligibilityService $requestCorrectSerialEligibilityService,
        private readonly CustomerNotRespondingEligibilityService $customerNotRespondingEligibilityService,
        private readonly SerialInsightService $serialInsightService,
        private readonly NotificationChannelAvailabilityService $channelAvailabilityService,
        private readonly InteraktTemplateConfigurationValidator $interaktTemplateConfigurationValidator,
        private readonly RequestSerialCommunicationHistoryService $requestSerialCommunicationHistoryService,
        private readonly RequestCorrectSerialCommunicationHistoryService $requestCorrectSerialCommunicationHistoryService,
        private readonly InquiryOrderLinkEligibilityService $inquiryOrderLinkEligibilityService,
        private readonly CustomerCorrectionEligibilityService $customerCorrectionEligibilityService,
        private readonly SerialCorrectionEligibilityService $serialCorrectionEligibilityService,
        private readonly DeviceModelCorrectionEligibilityService $deviceModelCorrectionEligibilityService,
        private readonly IdentityCorrectionEligibilityEvaluator $identityCorrectionEligibilityEvaluator,
        private readonly SerialValidationService $serialValidationService,
        private readonly CommunicationActionRegistry $communicationActionRegistry,
        private readonly CommunicationActionEligibilityService $communicationActionEligibilityService,
        private readonly CommunicationActionAvailabilityService $communicationActionAvailabilityService,
        private readonly CommunicationActionTargetProviderRegistry $communicationActionTargetProviderRegistry,
        private readonly RefundCalculationService $refundCalculationService,
    ) {}

    public function resolve(string $component): WorkspaceComponent
    {
        $resolved = WorkspaceComponent::tryFrom($component);

        if ($resolved === null) {
            abort(404);
        }

        return $resolved;
    }

    public function authorize(WorkspaceComponent $component, Incident $incident, User $user): void
    {
        $authorized = match ($component) {
            WorkspaceComponent::Assign => $user->can('reassign', $incident),
            WorkspaceComponent::Action => $this->canUseActionDialog($incident, $user),
            WorkspaceComponent::Remark => $user->can('create', Remark::class),
            WorkspaceComponent::Resolve => $user->can('update', $incident)
                && $incident->status !== IncidentStatus::Closed,
            WorkspaceComponent::Close => $user->can('update', $incident)
                && $incident->status !== IncidentStatus::Closed
                && ! app(BusinessHoldService::class)->hasActiveHold($incident),
            WorkspaceComponent::Timeline => $user->can('view', $incident),
            WorkspaceComponent::RequestSerialNumber => $user->can('update', $incident)
                && $this->requestSerialEligibilityService->canShowAction($incident),
            WorkspaceComponent::RequestCorrectSerial => $user->can('update', $incident)
                && $this->requestCorrectSerialEligibilityService->canShowAction($incident),
            WorkspaceComponent::CustomerNotResponding => $user->can('update', $incident)
                && $this->customerNotRespondingEligibilityService->canShowAction($incident),
            WorkspaceComponent::LinkOrder => $this->inquiryOrderLinkEligibilityService->canShowAction($incident, $user),
            WorkspaceComponent::CorrectCustomerDetails => $this->customerCorrectionEligibilityService->canShowAction($incident, $user),
            WorkspaceComponent::CorrectSerialNumber => $this->identityCorrectionEligibilityEvaluator->canCorrectDeviceIdentity($incident, $user),
            WorkspaceComponent::CorrectDeviceModel => $this->identityCorrectionEligibilityEvaluator->canCorrectDeviceIdentity($incident, $user),
            WorkspaceComponent::CommunicationAction => $this->canOpenCommunicationAction($incident, $user),
            WorkspaceComponent::RefundRequest => $user->can('refunds.create') && $incident->order_id !== null,
        };

        if (! $authorized) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    public function view(WorkspaceComponent $component): string
    {
        if ($component === WorkspaceComponent::CommunicationAction) {
            $actionKey = (string) request()->query('key', '');

            if ($actionKey === CommunicationActionKey::RefundConfirmation->value) {
                return 'customer-360.fragments.communication-action-refund-form';
            }
        }

        return $component->view();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(
        WorkspaceComponent $component,
        Incident $incident,
        ?WorkspaceRequestContext $requestContext = null,
    ): array {
        return match ($component) {
            WorkspaceComponent::Assign => [
                'incident' => $incident,
                'reassignableAdmins' => $this->assignmentService->reassignableAdmins(),
                ...$this->assignWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::Action => [
                'incident' => $incident,
                'reassignableAdmins' => $this->assignmentService->reassignableAdmins(),
                'actionCapabilities' => $this->actionCapabilities($incident, auth()->user()),
                'escalationTarget' => app(ServiceCaseEscalationService::class)->resolveLevel1Target(),
                'selectedAction' => WorkspaceActionType::tryFrom((string) request()->query('action'))
                    ?? ($incident->status === IncidentStatus::Closed
                        ? WorkspaceActionType::Reopen
                        : WorkspaceActionType::Assign),
                'exceptionReasons' => ServiceCaseCloseExceptionReason::cases(),
                'closeReasonsForClosing' => ServiceCaseCloseReasonForClosing::cases(),
                'closeResolutionTypes' => ServiceCaseCloseResolutionType::cases(),
                'closeNotificationPreferences' => ServiceCaseCloseNotificationPreference::cases(),
                ...$this->actionWorkspaceFields($requestContext, $incident),
                ...$this->actionRemarkUsers(),
            ],
            WorkspaceComponent::Remark => [
                'incident' => $incident,
                'mentionUsers' => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name'),
                ...$this->remarkWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::Resolve => [
                'incident' => $incident,
                ...$this->statusWorkspaceFields(WorkspaceComponent::Resolve, $requestContext, $incident),
                ...$this->actionRemarkUsers(),
            ],
            WorkspaceComponent::Close => [
                'incident' => $incident,
                ...$this->statusWorkspaceFields(WorkspaceComponent::Close, $requestContext, $incident),
                ...$this->actionRemarkUsers(),
            ],
            WorkspaceComponent::Timeline => [
                'incident' => $incident,
                'activityTimeline' => $this->activityTimelineService->forIncident($incident),
            ],
            WorkspaceComponent::BatchTransaction => [],
            WorkspaceComponent::BatchDeviceModel => [],
            WorkspaceComponent::RequestSerialNumber => [
                'incident' => $incident,
                ...$this->requestSerialWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::RequestCorrectSerial => [
                'incident' => $incident,
                ...$this->requestCorrectSerialWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::CustomerNotResponding => [
                'incident' => $incident,
                ...$this->customerNotRespondingWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::LinkOrder => [
                'incident' => $incident,
                ...$this->linkOrderWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::CorrectCustomerDetails => [
                'incident' => $incident,
                ...$this->correctCustomerDetailsWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::CorrectSerialNumber => [
                'incident' => $incident,
                ...$this->correctSerialNumberWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::CorrectDeviceModel => [
                'incident' => $incident,
                ...$this->correctDeviceModelWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::CommunicationAction => [
                'incident' => $incident,
                ...$this->communicationActionWorkspaceFields($requestContext, $incident),
            ],
            WorkspaceComponent::RefundRequest => [
                'incident' => $incident,
                ...$this->refundRequestWorkspaceFields($requestContext, $incident),
            ],
        };
    }

    private function canOpenCommunicationAction(Incident $incident, User $user): bool
    {
        if (! $user->can('update', $incident)) {
            return false;
        }

        $actionKey = (string) request()->query('key', '');

        if ($actionKey === '') {
            return $this->communicationActionTargetProviderRegistry->hasEligibleCenterAction($incident, $user);
        }

        if (! $this->communicationActionRegistry->has($actionKey)) {
            return false;
        }

        $definition = $this->communicationActionRegistry->get($actionKey);

        return $this->communicationActionEligibilityService->canShowAction($definition, $incident, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function communicationActionWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $actionKey = (string) request()->query('key', '');

        if ($actionKey === CommunicationActionKey::RefundConfirmation->value) {
            return $this->refundCommunicationActionWorkspaceFields($requestContext, $incident);
        }

        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $selectedActionKey = $actionKey !== '' && $this->communicationActionTargetProviderRegistry->isCenterAction($actionKey)
            ? $actionKey
            : null;

        $centerConfig = $this->communicationActionTargetProviderRegistry->buildCenterConfig(
            incident: $incident,
            user: $user,
            selectedActionKey: $selectedActionKey,
        );

        if (($centerConfig['selectedActionKey'] ?? null) === null) {
            return [];
        }

        return [
            'communicationCenterMode' => true,
            'workspaceActionUrl' => $centerConfig['actionUrls'][$centerConfig['selectedActionKey']],
            'workspaceContext' => $requestContext->context->value,
            'communicationCenterConfig' => $centerConfig,
            'canSendAction' => (bool) ($centerConfig['selectedCanSend'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundCommunicationActionWorkspaceFields(
        ?WorkspaceRequestContext $requestContext,
        Incident $incident,
    ): array {
        $definition = $this->communicationActionRegistry->get(CommunicationActionKey::RefundConfirmation);
        $incident->loadMissing('order');
        $order = $incident->order;
        $channelAvailability = $this->communicationActionAvailabilityService->forDefinition($definition, $order);

        return [
            'workspaceActionUrl' => route('incidents.workspace.communication-action', [
                'incident' => $incident,
                'key' => $definition->key->value,
            ]),
            'workspaceContext' => $requestContext->context->value,
            'communicationAction' => $definition,
            'customerName' => $order?->customer_name,
            'customerPhone' => $order?->customer_phone,
            'customerEmail' => $order?->customer_email,
            'channelAvailability' => $channelAvailability,
            'canSendAction' => $this->communicationActionAvailabilityService->hasDeliverableChannel($channelAvailability),
            'interaktTemplateDiagnostics' => $definition->whatsappTemplate !== null
                ? $this->interaktTemplateConfigurationValidator->diagnosticsFor($definition->whatsappTemplate)
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkOrderWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.link-order', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundRequestWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing('order');
        $order = $incident->order;
        $calculation = $order !== null
            ? $this->refundCalculationService->calculate($order)
            : null;

        return [
            'workspaceActionUrl' => route('incidents.workspace.refund-request', $incident),
            'workspaceContext' => $requestContext->context->value,
            'refund' => new RefundRequest,
            'calculation' => $calculation,
            'preferredMethods' => CustomerPreferredRefundMethod::cases(),
            'formPayload' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctSerialNumberWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing('order');
        $order = $incident->order;
        $currentSerial = filled($order?->serial_number)
            ? trim((string) $order->serial_number)
            : null;
        $currentValidation = null;
        $currentDuplicateOrderId = null;

        if ($order !== null && $currentSerial !== null) {
            $currentValidation = $this->serialValidationService->validateForOrder($currentSerial, $order);
            $duplicateOwner = Order::query()
                ->where('serial_number', $currentValidation->normalizedSerial)
                ->whereKeyNot($order->id)
                ->first();
            $currentDuplicateOrderId = $duplicateOwner?->order_id;
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.correct-serial-number', $incident),
            'workspaceValidationUrl' => route('incidents.workspace.correct-serial-number.validate', $incident),
            'workspaceContext' => $requestContext->context->value,
            'currentSerial' => $currentSerial,
            'currentValidation' => $currentValidation,
            'currentDuplicateOrderId' => $currentDuplicateOrderId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctDeviceModelWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing('order.deviceModel');
        $order = $incident->order;

        return [
            'workspaceActionUrl' => route('incidents.workspace.correct-device-model', $incident),
            'workspaceContext' => $requestContext->context->value,
            'currentDeviceModel' => $order?->device_model,
            'currentDeviceModelId' => $order?->device_model_id,
            'deviceModels' => app(DeviceModelSettingsService::class)->activeOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctCustomerDetailsWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        return [
            'workspaceActionUrl' => route('incidents.workspace.correct-customer-details', $incident),
            'workspaceContext' => $requestContext->context->value,
            'customerName' => $order?->customer_name,
            'customerPhone' => $order?->customer_phone,
            'customerEmail' => $order?->customer_email,
            'canCorrectSerialNumber' => $order !== null
                && auth()->user() !== null
                && $this->serialCorrectionEligibilityService->canShowAction($incident, auth()->user()),
            'canCorrectDeviceModel' => $order !== null
                && auth()->user() !== null
                && $this->deviceModelCorrectionEligibilityService->canShowAction($incident, auth()->user()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSerialWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing(['order', 'activeWaitingState']);
        $order = $incident->order;
        $channelAvailability = $this->channelAvailabilityService->forRequestSerialNumber($order);

        return [
            'workspaceActionUrl' => route('incidents.workspace.request-serial', $incident),
            'workspaceContext' => $requestContext->context->value,
            'customerName' => $order?->customer_name,
            'customerPhone' => $order?->customer_phone,
            'channelAvailability' => $channelAvailability,
            'canSendRequest' => $this->channelAvailabilityService->hasDeliverableChannel($channelAvailability),
            'interaktTemplateDiagnostics' => $this->interaktTemplateConfigurationValidator
                ->diagnosticsFor(WhatsAppTemplate::RequestSerialNumber),
            'hasActiveSerialWaitingState' => $incident->activeWaitingState !== null
                && $incident->activeWaitingState->waiting_reason === WaitingReason::SerialNumber,
            'communicationHistory' => $order !== null
                ? $this->requestSerialCommunicationHistoryService->forOrder($order)
                : [
                    'whatsapp' => ['status' => 'not_sent', 'status_label' => 'NOT SENT'],
                    'email' => ['status' => 'not_sent', 'status_label' => 'NOT SENT'],
                ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerNotRespondingWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing(['order', 'activeWaitingState']);
        $order = $incident->order;
        $channelAvailability = $this->channelAvailabilityService->forCallbackSchedule($order);

        return [
            'workspaceActionUrl' => route('incidents.workspace.customer-not-responding', $incident),
            'workspaceContext' => $requestContext->context->value,
            'customerName' => $order?->customer_name,
            'customerPhone' => $order?->customer_phone,
            'supportReference' => $incident->reference_no,
            'channelAvailability' => $channelAvailability,
            'canSendRequest' => $this->channelAvailabilityService->hasDeliverableChannel($channelAvailability),
            'interaktTemplateDiagnostics' => $this->interaktTemplateConfigurationValidator
                ->diagnosticsFor(WhatsAppTemplate::CallbackSchedule),
            'hasActiveCustomerNotRespondingWaitingState' => $incident->activeWaitingState !== null
                && $incident->activeWaitingState->waiting_reason === WaitingReason::CustomerNotResponding,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestCorrectSerialWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        $incident->loadMissing('order');
        $order = $incident->order;
        $channelAvailability = $this->channelAvailabilityService->forRequestCorrectSerial($order);
        $insight = $order !== null ? $this->serialInsightService->analyze($order) : null;

        return [
            'workspaceActionUrl' => route('incidents.workspace.request-correct-serial', $incident),
            'workspaceContext' => $requestContext->context->value,
            'customerName' => $order?->customer_name,
            'customerPhone' => $order?->customer_phone,
            'currentSerial' => $order?->serial_number,
            'serialInsightStatus' => $insight?->status->label(),
            'serialInsightConfidence' => $insight?->confidence->label(),
            'serialInsightExplanation' => $insight?->explanation,
            'channelAvailability' => $channelAvailability,
            'canSendRequest' => $this->channelAvailabilityService->hasDeliverableChannel($channelAvailability),
            'interaktTemplateDiagnostics' => $this->interaktTemplateConfigurationValidator
                ->diagnosticsFor(WhatsAppTemplate::RequestCorrectSerial),
            'communicationHistory' => $order !== null
                ? $this->requestCorrectSerialCommunicationHistoryService->forOrder($order)
                : [
                    'whatsapp' => ['status' => 'not_sent', 'status_label' => 'NOT SENT'],
                    'email' => ['status' => 'not_sent', 'status_label' => 'NOT SENT'],
                ],
        ];
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array<string, mixed>
     */
    public function batchTransactionViewData(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): array {
        $incidents = Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->sortBy(fn (Incident $incident): int => array_search($incident->id, $incidentIds, true) ?: PHP_INT_MAX)
            ->values();

        return [
            'incidents' => $incidents,
            'selectedCount' => count($incidentIds),
            'workspaceActionUrl' => route('dashboard.workspace.batch-transaction'),
            'workspaceContext' => $requestContext->context->value,
            'incidentIds' => $incidentIds,
        ];
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array<string, mixed>
     */
    public function batchDeviceModelViewData(
        array $incidentIds,
        WorkspaceRequestContext $requestContext,
    ): array {
        $incidents = Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->sortBy(fn (Incident $incident): int => array_search($incident->id, $incidentIds, true) ?: PHP_INT_MAX)
            ->values();

        return [
            'incidents' => $incidents,
            'selectedCount' => count($incidentIds),
            'deviceModels' => app(DeviceModelSettingsService::class)->activeOptions(),
            'workspaceActionUrl' => route('dashboard.workspace.batch-device-model'),
            'workspaceContext' => $requestContext->context->value,
            'incidentIds' => $incidentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.action', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    private function canUseActionDialog(Incident $incident, User $user): bool
    {
        $capabilities = $this->actionCapabilities($incident, $user);

        return $capabilities['assign'] || $capabilities['close'] || $capabilities['reopen'] || $capabilities['escalate'];
    }

    /**
     * @return array<string, bool>
     */
    private function actionCapabilities(Incident $incident, User $user): array
    {
        $canUpdate = $user->can('update', $incident);
        $isClosed = $incident->status === IncidentStatus::Closed;
        $hasBusinessHold = app(BusinessHoldService::class)->hasActiveHold($incident);

        return [
            'assign' => $user->can('reassign', $incident) && ! $isClosed,
            'close' => $canUpdate && ! $isClosed && ! $hasBusinessHold,
            'reopen' => $canUpdate && $isClosed,
            'escalate' => app(ServiceCaseEscalationService::class)->canEscalate($incident, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.assign', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remarkWorkspaceFields(?WorkspaceRequestContext $requestContext, Incident $incident): array
    {
        if ($requestContext === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route('incidents.workspace.remark', $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusWorkspaceFields(
        WorkspaceComponent $component,
        ?WorkspaceRequestContext $requestContext,
        Incident $incident,
    ): array {
        if ($requestContext === null) {
            return [];
        }

        $route = match ($component) {
            WorkspaceComponent::Resolve => 'incidents.workspace.resolve',
            WorkspaceComponent::Close => 'incidents.workspace.close',
            default => null,
        };

        if ($route === null) {
            return [];
        }

        return [
            'workspaceActionUrl' => route($route, $incident),
            'workspaceContext' => $requestContext->context->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionRemarkUsers(): array
    {
        return [
            'mentionUsers' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name'),
        ];
    }
}
