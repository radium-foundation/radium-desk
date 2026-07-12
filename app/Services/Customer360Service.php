<?php

namespace App\Services;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\TimelineViewModel;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AI\AIService;
use App\Services\AI\AIWorkbenchService;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\Bonvoice\BonvoiceCustomerCallService;
use App\Services\Bonvoice\BonvoiceCustomerContactIntelligenceService;
use App\Services\Customer360\Customer360OperationsHealthService;
use App\Services\Customer360\Customer360SlaMetricsService;
use App\Services\Customer360\Customer360ActionVisibilityService;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\Interakt\RequestCorrectSerialCommunicationHistoryService;
use App\Services\Interakt\RequestCorrectSerialEligibilityService;
use App\Services\Interakt\RequestSerialCommunicationHistoryService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\Interakt\CustomerNotRespondingEligibilityService;
use App\Services\Inquiry\InquiryOrderLinkEligibilityService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxSyncTimelineService;
use App\Support\RadiumBox\RadiumBoxSyncErrorFormatter;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\TimelineService;
use App\Support\Customer360\Customer360HealthCardPresenter;
use App\Support\Customer360\Customer360InsightsPresenter;
use App\Support\Customer360\Customer360IraAdvisorPresenter;
use App\Support\Customer360\Customer360OverflowMenuPresenter;
use App\Support\Customer360\Journey\CustomerJourneyBuilder;
use App\Support\Customer360\RdServiceStatusResolver;
use App\Support\Customer360\ScheduledSupportAppointmentContext;
use App\Support\AppDateFormatter;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Carbon;

class Customer360Service
{
    public function __construct(
        private readonly Customer360TimelineService $customer360TimelineService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
        private readonly RadiumBoxSyncTimelineService $syncTimelineService,
        private readonly RadiumBoxSyncErrorFormatter $syncErrorFormatter,
        private readonly RequestSerialNumberEligibilityService $requestSerialEligibilityService,
        private readonly RequestCorrectSerialEligibilityService $requestCorrectSerialEligibilityService,
        private readonly CustomerNotRespondingEligibilityService $customerNotRespondingEligibilityService,
        private readonly InquiryOrderLinkEligibilityService $inquiryOrderLinkEligibilityService,
        private readonly RequestSerialCommunicationHistoryService $requestSerialCommunicationHistoryService,
        private readonly RequestCorrectSerialCommunicationHistoryService $requestCorrectSerialCommunicationHistoryService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly AIService $aiService,
        private readonly OperationsAdvisorService $operationsAdvisorService,
        private readonly AIWorkbenchService $aiWorkbenchService,
        private readonly IRAExecutiveSummaryService $executiveSummaryService,
        private readonly Customer360OperationsHealthService $operationsHealthService,
        private readonly Customer360SlaMetricsService $slaMetricsService,
        private readonly BonvoiceCustomerCallService $bonvoiceCustomerCallService,
        private readonly BonvoiceCustomerContactIntelligenceService $bonvoiceContactIntelligenceService,
        private readonly Customer360ActionVisibilityService $actionVisibilityService,
        private readonly RdServiceStatusResolver $rdServiceStatusResolver,
        private readonly ScheduledSupportAppointmentContext $scheduledSupportAppointmentContext,
        private readonly CustomerJourneyBuilder $customerJourneyBuilder,
        private readonly Customer360HealthCardPresenter $healthCardPresenter,
        private readonly Customer360InsightsPresenter $insightsPresenter,
        private readonly Customer360IraAdvisorPresenter $iraAdvisorPresenter,
        private readonly CommunicationActionEligibilityService $communicationActionEligibilityService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function drawerData(Incident $incident): array
    {
        $incident->loadMissing([
            'order.deviceModel',
            'activeWaitingState',
            'assignee',
        ]);

        if ($incident->isActive()) {
            $incident->loadMissing([
                'supportAppointments' => fn ($query) => $query
                    ->where('status', SupportAppointmentStatus::Scheduled)
                    ->latest('preferred_date')
                    ->latest('id'),
            ]);
        }

        $actionVisibility = $this->actionVisibilityService->forIncident($incident, auth()->user());
        $order = $incident->order;

        if ($order === null) {
            return array_merge($this->emptyDrawerData($incident), [
                'canRequestSerialNumber' => $actionVisibility['canRequestSerialNumber'],
                'canRequestCorrectSerial' => $actionVisibility['canRequestCorrectSerial'],
                'canCustomerNotResponding' => $actionVisibility['canCustomerNotResponding'],
                'canLinkOrder' => $actionVisibility['canLinkOrder'],
                'canCorrectCustomerDetails' => $actionVisibility['canCorrectCustomerDetails'],
                'canCorrectSerialNumber' => $actionVisibility['canCorrectSerialNumber'],
                'correctCustomerDetailsEligibility' => $actionVisibility['correctCustomerDetailsEligibility'],
                'correctSerialNumberEligibility' => $actionVisibility['correctSerialNumberEligibility'],
                'showIdentityCorrectionActions' => $actionVisibility['showIdentityCorrectionActions'],
                'isWaitingForCustomer' => $actionVisibility['isWaitingForCustomer'],
                'hideWorkflowActions' => $actionVisibility['hideWorkflowActions'],
                'hasRecommendedActions' => $actionVisibility['hasRecommendedActions'],
                'communicationActions' => $this->communicationActionEligibilityService->menuItems($incident, auth()->user()),
            ], $this->overflowMenuPayload($incident, null));
        }

        $fullModelName = $order->displayDeviceModelName();
        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $customer = $this->customerSection($order);
        $activeServices = $this->activeServices($incident, $order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $actionVisibility = $this->actionVisibilityService->forIncident($incident, auth()->user());

        return [
            'incident' => $incident,
            'order' => $order,
            'customer' => $customer,
            'device' => $this->deviceSection($order, $fullModelName, $incident),
            'sync_history' => $this->syncTimelineService->forOrder($order),
            'activeServices' => $activeServices,
            'summary' => $summary,
            'healthCard' => $this->healthCard($order, $customer, $activeServices, $summary),
            'canRequestSerialNumber' => $actionVisibility['canRequestSerialNumber'],
            'canRequestCorrectSerial' => $actionVisibility['canRequestCorrectSerial'],
            'canCustomerNotResponding' => $actionVisibility['canCustomerNotResponding'],
            'canLinkOrder' => $actionVisibility['canLinkOrder'],
            'canCorrectCustomerDetails' => $actionVisibility['canCorrectCustomerDetails'],
            'canCorrectSerialNumber' => $actionVisibility['canCorrectSerialNumber'],
            'correctCustomerDetailsEligibility' => $actionVisibility['correctCustomerDetailsEligibility'],
            'correctSerialNumberEligibility' => $actionVisibility['correctSerialNumberEligibility'],
            'showIdentityCorrectionActions' => $actionVisibility['showIdentityCorrectionActions'],
            'isWaitingForCustomer' => $actionVisibility['isWaitingForCustomer'],
            'hideWorkflowActions' => $actionVisibility['hideWorkflowActions'],
            'hasRecommendedActions' => $actionVisibility['hasRecommendedActions'],
            'serialRequestState' => $this->serialRequestState($order),
            'correctSerialRequestState' => $this->correctSerialRequestState($order),
            'waitingStateCard' => $waitingStateCard,
            'supportAppointments' => $incident->isActive()
                ? $incident->supportAppointments
                : collect(),
            'executiveSummaryUrl' => route('dashboard.service-cases.customer-360.executive-summary', $incident),
            'timelineTabUrl' => route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1',
            'aiTabUrl' => route('dashboard.service-cases.customer-360.ai-workbench', $incident),
            'communicationActions' => $this->communicationActionEligibilityService->menuItems($incident, auth()->user()),
            ...$this->overflowMenuPayload(
                $incident,
                $order,
                $this->serialRequestState($order),
                $this->correctSerialRequestState($order),
                $incident->isActive() ? $incident->supportAppointments : collect(),
            ),
        ];
    }

    /**
     * @param  array{requested?: bool, requested_at_label?: string|null}  $serialRequestState
     * @param  array{requested?: bool, requested_at_label?: string|null}  $correctSerialRequestState
     * @return array{
     *     overflowMenuGroups: list<array{label: string, items: list<array<string, mixed>>}>,
     *     paletteActions: list<array<string, mixed>>,
     * }
     */
    private function overflowMenuPayload(
        Incident $incident,
        ?Order $order,
        array $serialRequestState = ['requested' => false],
        array $correctSerialRequestState = ['requested' => false],
        $supportAppointments = null,
    ): array {
        $user = auth()->user();

        if ($user === null) {
            return [
                'overflowMenuGroups' => [],
                'paletteActions' => [],
            ];
        }

        $overflowMenu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $user,
            $order,
            $serialRequestState,
            $correctSerialRequestState,
            $supportAppointments ?? collect(),
        );

        return [
            'overflowMenuGroups' => $overflowMenu['groups'],
            'paletteActions' => $overflowMenu['paletteActions'],
        ];
    }

    /**
     * @return array{html: string}
     */
    public function executiveSummaryPayload(Incident $incident): array
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);
        $order = $incident->order;

        if ($order === null) {
            return ['html' => ''];
        }

        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $activeServices = $this->activeServices($incident, $order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $timeline = $this->customer360TimelineService->forOrder($order);
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $supportAppointment = $this->scheduledSupportAppointmentContext->forIncident($incident);
        $customerJourney = $this->customerJourneyBuilder->forIncident($incident, new CustomerJourneyBuildContext(
            incident: $incident,
            waitingState: $waitingStateCard,
            supportAppointment: $supportAppointment,
            timeline: $timeline,
        ));
        $snapshot = new AIContextBuildSnapshot(
            customerSummary: $summary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
            supportAppointment: $supportAppointment,
            customerJourney: $customerJourney,
        );
        $aiBundle = $this->aiService->buildBundle($incident, $snapshot, $scopeCache);
        $operationsAdvisorInsights = $this->operationsAdvisorService->incidentInsightsFromBundle($incident, $aiBundle, $snapshot);
        $executiveSummary = $this->executiveSummaryService->buildFromBundle(
            $incident,
            $aiBundle,
            $snapshot,
            $operationsAdvisorInsights,
        );

        return [
            'html' => view('customer-360.partials.executive-summary', [
                'incident' => $incident,
                'executiveSummary' => $executiveSummary,
                'canRequestCorrectSerial' => $this->requestCorrectSerialEligibilityService->canShowAction($incident),
                'correctSerialRequestState' => $order !== null
                    ? $this->correctSerialRequestState($order)
                    : ['requested' => false],
            ])->render(),
        ];
    }

    /**
     * @return array{timeline: TimelineViewModel, html: string, operationsHealth: array<string, mixed>, slaMetrics: \App\Data\Customer360\Customer360SlaMetrics|null, customerHealthCard: array<string, mixed>|null, customerInsights: list<array{key: string, label: string, description: string, icon: string}>, iraAdvisor: array<string, mixed>|null}
     */
    public function timelineTabPayload(Incident $incident, int $offset = 0): array
    {
        $incident->loadMissing('order');
        $order = $incident->order;
        $viewModel = $this->customer360TimelineService->forIncident($incident, $offset);
        $timelineUrl = route('dashboard.service-cases.customer-360.timeline', $incident);
        $operationsHealth = $this->operationsHealthService->forIncident($incident);
        $slaMetrics = $order !== null
            ? $this->slaMetricsService->forOrder($order)
            : null;
        $customerHealthCard = $this->customerHealthCardViewData($incident, $order);
        $customerInsights = $this->customerInsightsViewData($incident, $order, $customerHealthCard);
        $iraAdvisor = $this->iraAdvisorViewData($incident, $order, $customerHealthCard, $slaMetrics);

        return [
            'timeline' => $viewModel,
            'operationsHealth' => $operationsHealth,
            'slaMetrics' => $slaMetrics,
            'customerHealthCard' => $customerHealthCard,
            'customerInsights' => $customerInsights,
            'iraAdvisor' => $iraAdvisor,
            'html' => view('customer-360.partials.timeline-tab', [
                'timeline' => $viewModel,
                'timelineLoadMoreUrl' => $timelineUrl,
                'timelineRefreshUrl' => $timelineUrl,
                'operationsHealth' => $operationsHealth,
                'slaMetrics' => $slaMetrics,
                'customerHealthCard' => $customerHealthCard,
                'customerInsights' => $customerInsights,
                'iraAdvisor' => $iraAdvisor,
            ])->render(),
        ];
    }

    /**
     * @return array{html: string, workbench: AIWorkbenchDTO}
     */
    public function aiTabPayload(Incident $incident): array
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);
        $order = $incident->order;

        if ($order === null) {
            $aiBundle = $this->aiService->buildBundle($incident);
            $workbench = $this->aiWorkbenchService->buildFromBundle($incident, $aiBundle);

            return [
                'html' => view('customer-360.partials.ai-tab', [
                    'incident' => $incident,
                    'aiAssistant' => $aiBundle->response,
                    'operationsAdvisorInsights' => [],
                    'aiWorkbench' => $workbench,
                ])->render(),
                'workbench' => $workbench,
            ];
        }

        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $activeServices = $this->activeServices($incident, $order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $timeline = $this->customer360TimelineService->forOrder($order);
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $supportAppointment = $this->scheduledSupportAppointmentContext->forIncident($incident);
        $customerJourney = $this->customerJourneyBuilder->forIncident($incident, new CustomerJourneyBuildContext(
            incident: $incident,
            waitingState: $waitingStateCard,
            supportAppointment: $supportAppointment,
            timeline: $timeline,
        ));
        $snapshot = new AIContextBuildSnapshot(
            customerSummary: $summary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
            supportAppointment: $supportAppointment,
            customerJourney: $customerJourney,
        );
        $aiBundle = $this->aiService->buildBundle($incident, $snapshot, $scopeCache);
        $operationsAdvisorInsights = $this->operationsAdvisorService->incidentInsightsFromBundle($incident, $aiBundle, $snapshot);
        $workbench = $this->aiWorkbenchService->buildFromBundle($incident, $aiBundle);

        return [
            'html' => view('customer-360.partials.ai-tab', [
                'incident' => $incident,
                'aiAssistant' => $aiBundle->response,
                'operationsAdvisorInsights' => $operationsAdvisorInsights,
                'aiWorkbench' => $workbench,
            ])->render(),
            'workbench' => $workbench,
        ];
    }

    public function refreshAiWorkbench(Incident $incident): AIWorkbenchDTO
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);
        $bundle = $this->aiService->buildBundle($incident);

        return $this->aiWorkbenchService->buildFromBundle($incident, $bundle);
    }

    /**
     * @return array{timeline: \App\Data\TimelineViewModel, html: string}
     */
    public function timelinePayload(Incident $incident, int $offset = 0): array
    {
        $viewModel = $this->customer360TimelineService->forIncident($incident, $offset);

        return [
            'timeline' => $viewModel,
            'html' => view('customer-360.partials.timeline-section', [
                'viewModel' => $viewModel,
                'loadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
                'timelineRefreshUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
            ])->render(),
        ];
    }

    /**
     * @return array{device: array<string, mixed>, sync_history: list<array<string, mixed>>}
     */
    public function devicePayload(Incident $incident): array
    {
        $incident->loadMissing('order.deviceModel');
        $order = $incident->order;

        if ($order === null) {
            return [
                'device' => [],
                'sync_history' => [],
            ];
        }

        return [
            'device' => $this->deviceSection($order, $order->displayDeviceModelName(), $incident),
            'sync_history' => $this->syncTimelineService->forOrder($order),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function customerSection(Order $order): array
    {
        return [
            'name' => $order->customer_name,
            'mobile' => $order->customer_phone,
            'email' => $order->customer_email,
            'city' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceSection(Order $order, ?string $fullModelName, Incident $incident): array
    {
        $syncStatus = $this->enrichmentSyncStore->status($order->id);
        $metadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $hasSerial = filled($order->serial_number);
        $lastAttemptAt = $this->resolveLastAttemptAt($order);

        $lastSyncedAt = $order->radiumbox_last_sync_at;

        return [
            'model_short' => DeviceModelFormatter::shortDisplay($fullModelName),
            'model_canonical' => $fullModelName,
            'serial_number' => $order->serial_number,
            'serial_sync_status' => $syncStatus->value,
            'sync_status_label' => $syncStatus->label(),
            'sync_attempts' => $this->enrichmentSyncStore->attemptCount($order->id),
            'last_sync_at' => AppDateFormatter::format($lastSyncedAt, 'd M Y h:i A'),
            'last_attempt_at' => $lastAttemptAt,
            'last_sync_error' => $this->syncErrorFormatter->friendlyMessage(
                $order->radiumbox_last_sync_error,
                metadata: $metadata,
            ),
            'show_sync_diagnostics' => ! $order->isInquiryOrder() && ! $hasSerial,
            'show_sync_freshness' => ! $order->isInquiryOrder()
                && $hasSerial
                && $syncStatus === RadiumBoxEnrichmentSyncStatus::Synced,
            'last_synced_label' => $this->resolveLastSyncedLabel($lastSyncedAt),
            'last_synced_relative' => AppDateFormatter::timelineRelative($lastSyncedAt),
            'last_synced_tooltip' => AppDateFormatter::datetime($lastSyncedAt),
            'should_poll_sync' => ! $order->isInquiryOrder()
                && ! $hasSerial
                && $syncStatus === RadiumBoxEnrichmentSyncStatus::Pending,
            'device_refresh_url' => route('dashboard.service-cases.customer-360.device', $incident),
            'can_manual_sync' => $this->canManualRadiumBoxSync($order, $syncStatus),
            'manual_sync_url' => route('dashboard.service-cases.customer-360.radiumbox-sync', $incident),
            'order_id' => $order->isInquiryOrder() ? null : $order->order_id,
            'is_inquiry' => $order->isInquiryOrder(),
            'case_reference' => $order->isInquiryOrder() ? $incident->display_reference : null,
            'is_legacy_imported' => $order->isLegacyImported(),
            'legacy_import_tooltip' => $order->legacyImportTooltipTitle(),
            'service_reference' => $order->transaction_id,
        ];
    }

    private function resolveLastSyncedLabel(?Carbon $lastSyncedAt): ?string
    {
        if ($lastSyncedAt === null) {
            return null;
        }

        $localized = AppDateFormatter::inAppTimezone($lastSyncedAt);

        if ($localized === null) {
            return null;
        }

        if ($localized->isToday()) {
            return 'Today at '.$localized->format('h:i A');
        }

        if ($localized->isYesterday()) {
            return 'Yesterday at '.$localized->format('h:i A');
        }

        return AppDateFormatter::format($lastSyncedAt, 'd M Y h:i A');
    }

    private function resolveLastAttemptAt(Order $order): ?string
    {
        $lastAttemptIso = $this->enrichmentSyncStore->lastAttemptAt($order->id);

        if (is_string($lastAttemptIso) && $lastAttemptIso !== '') {
            return AppDateFormatter::format(Carbon::parse($lastAttemptIso), 'd M Y h:i A');
        }

        return AppDateFormatter::format($order->radiumbox_last_sync_at, 'd M Y h:i A');
    }

    private function canManualRadiumBoxSync(Order $order, RadiumBoxEnrichmentSyncStatus $syncStatus): bool
    {
        if ($order->isInquiryOrder()) {
            return false;
        }

        if (filled($order->serial_number)) {
            return false;
        }

        if ($syncStatus === RadiumBoxEnrichmentSyncStatus::Pending) {
            return false;
        }

        return in_array($syncStatus, [
            RadiumBoxEnrichmentSyncStatus::Failed,
            RadiumBoxEnrichmentSyncStatus::NotSynced,
            RadiumBoxEnrichmentSyncStatus::Synced,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $enrichmentMetadata
     * @return list<array{label: string, status: string, variant: string}>
     */
    private function activeServices(Incident $incident, Order $order, array $enrichmentMetadata): array
    {
        if ($order->isInquiryOrder()) {
            return [
                [
                    'label' => 'Enquiry',
                    'status' => 'Open',
                    'variant' => 'info',
                ],
            ];
        }

        $warranty = $this->normalizeServiceStatus($enrichmentMetadata['warranty'] ?? null);
        $amc = $this->normalizeServiceStatus($enrichmentMetadata['amc'] ?? null);
        $rdService = $this->rdServiceStatusResolver->resolve($incident, $order);

        return [
            [
                'label' => 'RD Service',
                'status' => $rdService['status'],
                'variant' => $rdService['variant'],
            ],
            [
                'label' => 'Warranty',
                'status' => $warranty,
                'variant' => $warranty === 'Not Available' ? 'neutral' : 'info',
            ],
            [
                'label' => 'AMC',
                'status' => $amc,
                'variant' => $amc === 'Not Available' ? 'neutral' : 'info',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerHealthCardViewData(Incident $incident, ?Order $order): ?array
    {
        if ($order === null) {
            return null;
        }

        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $healthCard = $this->healthCard(
            $order,
            $this->customerSection($order),
            $this->activeServices($incident, $order, $enrichmentMetadata),
            $summary,
        );

        return $this->healthCardPresenter->present($healthCard, $summary, $order->customer_phone);
    }

    /**
     * @param  array<string, mixed>|null  $healthCardViewModel
     * @return list<array{key: string, label: string, description: string, icon: string}>
     */
    private function customerInsightsViewData(Incident $incident, ?Order $order, ?array $healthCardViewModel): array
    {
        if ($order === null || $healthCardViewModel === null) {
            return [];
        }

        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);

        return $this->insightsPresenter->present(
            $healthCardViewModel,
            $scopeCache->customerSummary(),
            $order->customer_phone,
        );
    }

    /**
     * @param  array<string, mixed>|null  $healthCardViewModel
     * @return array<string, mixed>|null
     */
    private function iraAdvisorViewData(
        Incident $incident,
        ?Order $order,
        ?array $healthCardViewModel,
        ?\App\Data\Customer360\Customer360SlaMetrics $slaMetrics,
    ): ?array {
        if ($order === null) {
            return null;
        }

        $incident->loadMissing(['activeWaitingState', 'assignee']);
        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $activeServices = $this->activeServices($incident, $order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $timeline = $this->customer360TimelineService->forOrder($order);
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $supportAppointment = $this->scheduledSupportAppointmentContext->forIncident($incident);
        $customerJourney = $this->customerJourneyBuilder->forIncident($incident, new CustomerJourneyBuildContext(
            incident: $incident,
            waitingState: $waitingStateCard,
            supportAppointment: $supportAppointment,
            timeline: $timeline,
        ));
        $snapshot = new AIContextBuildSnapshot(
            customerSummary: $summary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
            supportAppointment: $supportAppointment,
            customerJourney: $customerJourney,
        );
        $user = auth()->user();
        $canEscalate = $user !== null
            && app(ServiceCaseEscalationService::class)->canEscalate($incident, $user);

        return $this->iraAdvisorPresenter->present([
            'incident' => $incident,
            'order' => $order,
            'customerSummary' => $summary,
            'healthCardViewModel' => $healthCardViewModel ?? [],
            'waitingStateCard' => $waitingStateCard,
            'supportAppointment' => $supportAppointment,
            'customerJourney' => $customerJourney,
            'slaMetrics' => $slaMetrics,
            'operationsAdvisorInsights' => $this->operationsAdvisorService->incidentInsights($incident, $snapshot),
            'actionVisibility' => $this->actionVisibilityService->forIncident($incident, $user),
            'canEscalate' => $canEscalate,
        ]);
    }

    /**
     * @param  array<string, string|null>  $customer
     * @param  list<array{label: string, status: string, variant: string}>  $activeServices
     * @param  array<string, int>  $summary
     * @return array<string, mixed>
     */
    private function healthCard(
        Order $order,
        array $customer,
        array $activeServices,
        array $summary,
    ): array {
        $warranty = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? 'Not Available';
        $communication = $this->requestSerialCommunicationHistoryService->forCustomerPhone($order->customer_phone);
        $repeatContact = $this->bonvoiceContactIntelligenceService->forCustomerPhone(
            $order->customer_phone,
            ($summary['open_cases'] ?? 0) > 0,
        );

        return [
            'name' => $customer['name'],
            'phone' => $customer['mobile'],
            'email' => $customer['email'],
            'warranty_status' => $warranty,
            'active_service_cases' => $summary['open_cases'] ?? 0,
            'last_payment' => $this->resolveLastPayment($order),
            'last_whatsapp' => $communication['whatsapp'],
            'last_email' => $communication['email'],
            'last_interaction_at' => null,
            'last_call' => $this->bonvoiceCustomerCallService->lastCallSummary($order->customer_phone),
            'repeat_contact' => $repeatContact === null ? null : [
                'summary' => $repeatContact->summaryLine,
                'total_today' => $repeatContact->totalToday,
                'missed_today' => $repeatContact->missedToday,
                'answered_today' => $repeatContact->answeredToday,
                'last_contact_at' => $repeatContact->lastContactAt,
                'contacts_last_24_hours' => $repeatContact->contactsLast24Hours,
                'high_urgency' => $repeatContact->highUrgency,
            ],
        ];
    }

    /**
     * @return array{label: string, occurred_at: \Illuminate\Support\Carbon}|null
     */
    private function resolveLastPayment(Order $order): ?array
    {
        if ($order->payment_date === null) {
            return null;
        }

        return [
            'label' => $this->formatPaymentSummary($order) ?? 'Payment received',
            'occurred_at' => $order->payment_date,
        ];
    }

    private function formatPaymentSummary(Order $order): ?string
    {
        $parts = [];

        if ($order->payment_amount !== null) {
            $parts[] = '₹'.number_format((float) $order->payment_amount, 2);
        }

        if (filled($order->payment_method)) {
            $parts[] = (string) $order->payment_method;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function normalizeServiceStatus(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not Available';
        }

        return trim($value);
    }

    /**
     * @return array{
     *     requested: bool,
     *     requested_at: \Illuminate\Support\Carbon|null,
     *     requested_at_label: string|null,
     * }
     */
    private function serialRequestState(Order $order): array
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ];
        }

        $history = $this->requestSerialCommunicationHistoryService->forOrder($order);
        $whatsappSent = ($history['whatsapp']['status'] ?? null) === 'sent';
        $emailSent = ($history['email']['status'] ?? null) === 'sent';

        if (! $whatsappSent && ! $emailSent) {
            return [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ];
        }

        $requestedAt = $this->latestSerialRequestTimestamp(
            $whatsappSent ? ($history['whatsapp']['last_sent_at'] ?? null) : null,
            $emailSent ? ($history['email']['last_sent_at'] ?? null) : null,
        );

        return [
            'requested' => true,
            'requested_at' => $requestedAt,
            'requested_at_label' => AppDateFormatter::format(
                $requestedAt,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ),
        ];
    }

    private function latestSerialRequestTimestamp(?Carbon $whatsappAt, ?Carbon $emailAt): ?Carbon
    {
        if ($whatsappAt === null) {
            return $emailAt;
        }

        if ($emailAt === null) {
            return $whatsappAt;
        }

        return $whatsappAt->greaterThan($emailAt) ? $whatsappAt : $emailAt;
    }

    /**
     * @return array{
     *     requested: bool,
     *     requested_at: \Illuminate\Support\Carbon|null,
     *     requested_at_label: string|null,
     * }
     */
    private function correctSerialRequestState(Order $order): array
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ];
        }

        $history = $this->requestCorrectSerialCommunicationHistoryService->forOrder($order);
        $whatsappSent = ($history['whatsapp']['status'] ?? null) === 'sent';
        $emailSent = ($history['email']['status'] ?? null) === 'sent';

        if (! $whatsappSent && ! $emailSent) {
            return [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ];
        }

        $requestedAt = $this->latestSerialRequestTimestamp(
            $whatsappSent ? ($history['whatsapp']['last_sent_at'] ?? null) : null,
            $emailSent ? ($history['email']['last_sent_at'] ?? null) : null,
        );

        return [
            'requested' => true,
            'requested_at' => $requestedAt,
            'requested_at_label' => AppDateFormatter::format(
                $requestedAt,
                RequestCorrectSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDrawerData(Incident $incident): array
    {
        $emptyCommunication = $this->requestSerialCommunicationHistoryService->forCustomerPhone(null);

        return [
            'incident' => $incident,
            'order' => null,
            'customer' => [
                'name' => null,
                'mobile' => null,
                'email' => null,
                'city' => null,
            ],
            'device' => [
                'model_short' => null,
                'model_canonical' => null,
                'serial_number' => null,
                'serial_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced->value,
                'sync_status_label' => RadiumBoxEnrichmentSyncStatus::NotSynced->label(),
                'sync_attempts' => 0,
                'last_sync_at' => null,
                'last_attempt_at' => null,
                'last_sync_error' => null,
                'show_sync_diagnostics' => false,
                'can_manual_sync' => false,
                'manual_sync_url' => null,
                'order_id' => null,
                'service_reference' => null,
            ],
            'sync_history' => [],
            'activeServices' => [],
            'summary' => [
                'total_orders' => 0,
                'total_devices' => 0,
                'open_cases' => 0,
                'closed_cases' => 0,
            ],
            'healthCard' => [
                'name' => null,
                'phone' => null,
                'email' => null,
                'warranty_status' => 'Not Available',
                'active_service_cases' => 0,
                'last_payment' => null,
                'last_whatsapp' => $emptyCommunication['whatsapp'],
                'last_email' => $emptyCommunication['email'],
                'last_interaction_at' => null,
                'last_call' => null,
            ],
            'canRequestSerialNumber' => false,
            'canRequestCorrectSerial' => false,
            'canCustomerNotResponding' => false,
            'canLinkOrder' => false,
            'canCorrectCustomerDetails' => false,
            'canCorrectSerialNumber' => false,
            'correctCustomerDetailsEligibility' => [
                'allowed' => false,
                'reason' => 'This service case is not linked to an order.',
            ],
            'correctSerialNumberEligibility' => [
                'allowed' => false,
                'reason' => 'This service case is not linked to an order.',
            ],
            'showIdentityCorrectionActions' => false,
            'isWaitingForCustomer' => false,
            'hideWorkflowActions' => false,
            'hasRecommendedActions' => false,
            'serialRequestState' => [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ],
            'correctSerialRequestState' => [
                'requested' => false,
                'requested_at' => null,
                'requested_at_label' => null,
            ],
            'waitingStateCard' => null,
            'supportAppointments' => collect(),
            'executiveSummaryUrl' => route('dashboard.service-cases.customer-360.executive-summary', $incident),
            'timelineTabUrl' => route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1',
            'aiTabUrl' => route('dashboard.service-cases.customer-360.ai-workbench', $incident),
        ];
    }
}
