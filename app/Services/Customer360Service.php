<?php

namespace App\Services;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIWorkbenchDTO;
use App\Data\TimelineViewModel;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AI\AIService;
use App\Services\AI\AIWorkbenchService;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\TimelineService;
use App\Support\DeviceModelFormatter;

class Customer360Service
{
    public function __construct(
        private readonly Customer360TimelineService $customer360TimelineService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
        private readonly RequestSerialNumberEligibilityService $requestSerialEligibilityService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly AIService $aiService,
        private readonly OperationsAdvisorService $operationsAdvisorService,
        private readonly AIWorkbenchService $aiWorkbenchService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function drawerData(Incident $incident): array
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState']);
        $order = $incident->order;

        if ($order === null) {
            return $this->emptyDrawerData($incident);
        }

        $fullModelName = $order->displayDeviceModelName();
        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];
        $customer = $this->customerSection($order);
        $activeServices = $this->activeServices($order, $enrichmentMetadata);
        $scopeCache = new CustomerScopeQueryCache($order->customer_phone);
        $summary = $scopeCache->customerSummary();
        $timeline = $this->customer360TimelineService->forOrder($order);
        $waitingStateCard = $this->waitingStateService->customer360Card($incident);
        $snapshot = new AIContextBuildSnapshot(
            customerSummary: $summary,
            activeServices: $activeServices,
            enrichmentMetadata: $enrichmentMetadata,
            timeline: $timeline,
            waitingStateCard: $waitingStateCard,
        );
        $aiBundle = $this->aiService->buildBundle($incident, $snapshot, $scopeCache);

        return [
            'incident' => $incident,
            'order' => $order,
            'customer' => $customer,
            'device' => $this->deviceSection($order, $fullModelName),
            'activeServices' => $activeServices,
            'summary' => $summary,
            'healthCard' => $this->healthCard($order, $customer, $activeServices, $summary, $timeline),
            'timeline' => $timeline,
            'timelineLoadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
            'canRequestSerialNumber' => $this->requestSerialEligibilityService->canShowAction($incident),
            'waitingStateCard' => $waitingStateCard,
            'aiAssistant' => $aiBundle->response,
            'operationsAdvisorInsights' => $this->operationsAdvisorService->incidentInsightsFromBundle($incident, $aiBundle, $snapshot),
            'aiWorkbench' => $this->aiWorkbenchService->buildFromBundle($incident, $aiBundle),
        ];
    }

    public function refreshAiWorkbench(Incident $incident): AIWorkbenchDTO
    {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);
        $bundle = $this->aiService->buildBundle($incident);

        return $this->aiWorkbenchService->buildFromBundle($incident, $bundle);
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
     * @return array<string, string|null>
     */
    private function deviceSection(Order $order, ?string $fullModelName): array
    {
        return [
            'model_short' => DeviceModelFormatter::shortDisplay($fullModelName),
            'model_canonical' => $fullModelName,
            'serial_number' => $order->serial_number,
            'order_id' => $order->order_id,
            'service_reference' => $order->transaction_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $enrichmentMetadata
     * @return list<array{label: string, status: string, variant: string}>
     */
    private function activeServices(Order $order, array $enrichmentMetadata): array
    {
        $warranty = $this->normalizeServiceStatus($enrichmentMetadata['warranty'] ?? null);
        $amc = $this->normalizeServiceStatus($enrichmentMetadata['amc'] ?? null);

        return [
            [
                'label' => 'RD Service',
                'status' => $order->isTransactionLocked() ? 'Active' : 'Pending',
                'variant' => $order->isTransactionLocked() ? 'success' : 'warning',
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
        TimelineViewModel $timeline,
    ): array {
        $events = $timeline->events();
        $lastPaymentEvent = $events->first(fn ($event) => $event->type === TimelineEventType::Payment);
        $lastWhatsAppEvent = $events->first(fn ($event) => $event->type === TimelineEventType::WhatsApp);
        $lastInteraction = $events->first();
        $warranty = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? 'Not Available';

        return [
            'name' => $customer['name'],
            'phone' => $customer['mobile'],
            'email' => $customer['email'],
            'warranty_status' => $warranty,
            'active_service_cases' => $summary['open_cases'] ?? 0,
            'last_payment' => $this->resolveLastPayment($order, $lastPaymentEvent),
            'last_whatsapp_status' => $lastWhatsAppEvent?->statusLabel,
            'last_interaction_at' => $lastInteraction?->occurredAt,
            'last_call' => null,
            'last_email' => null,
        ];
    }

    /**
     * @return array{label: string, occurred_at: \Illuminate\Support\Carbon}|null
     */
    private function resolveLastPayment(Order $order, ?\App\Data\TimelineEvent $paymentEvent): ?array
    {
        if ($paymentEvent !== null) {
            $label = $paymentEvent->summary ?? $paymentEvent->title;

            return [
                'label' => $label,
                'occurred_at' => $paymentEvent->occurredAt,
            ];
        }

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
     * @return array<string, mixed>
     */
    private function emptyDrawerData(Incident $incident): array
    {
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
                'order_id' => null,
                'service_reference' => null,
            ],
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
                'last_whatsapp_status' => null,
                'last_interaction_at' => null,
                'last_call' => null,
                'last_email' => null,
            ],
            'timeline' => new TimelineViewModel(
                groups: collect(),
                totalCount: 0,
                loadedCount: 0,
                offset: 0,
                limit: TimelineService::DEFAULT_PAGE_SIZE,
                hasMore: false,
            ),
            'timelineLoadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
            'canRequestSerialNumber' => false,
            'waitingStateCard' => null,
            'aiAssistant' => ($bundle = $this->aiService->buildBundle($incident))->response,
            'operationsAdvisorInsights' => [],
            'aiWorkbench' => $this->aiWorkbenchService->buildFromBundle($incident, $bundle),
        ];
    }
}
