<?php

namespace App\Services\AI;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIContextDTO;
use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Data\TimelineViewModel;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Services\IncidentWaitingStateService;
use App\Services\Knowledge\KnowledgeEngine;
use App\Services\Knowledge\KnowledgeIntelligenceMapper;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseAutomationStatusService;
use App\Services\Timeline\Customer360TimelineService;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IncidentAIContextBuilder
{
    private const RECENT_ACTIVITY_LIMIT = 8;

    private const REMARKS_SUMMARY_LIMIT = 5;

    public function __construct(
        private readonly KnowledgeEngine $knowledgeEngine,
        private readonly KnowledgeIntelligenceMapper $knowledgeMapper,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
        private readonly ServiceCaseAutomationStatusService $automationStatusService,
        private readonly Customer360TimelineService $customer360TimelineService,
        private readonly SerialPlaceholderService $serialPlaceholderService,
        private readonly AIRiskScoringService $riskScoringService,
    ) {}

    public function build(
        Incident $incident,
        ?AIContextBuildSnapshot $snapshot = null,
        ?KnowledgeResponseDTO $knowledge = null,
        ?CustomerScopeQueryCache $scopeCache = null,
    ): AIContextDTO {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);

        $order = $incident->order;
        $scopeCache ??= new CustomerScopeQueryCache($order?->customer_phone);

        $enrichmentMetadata = $snapshot?->enrichmentMetadata
            ?? ($order !== null ? ($this->enrichmentSyncStore->metadata($order->id) ?? []) : []);

        $activeServices = $snapshot?->activeServices
            ?? ($order !== null ? $this->activeServices($order, $enrichmentMetadata) : []);

        $warrantyStatus = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? 'Not Available';
        $waitingState = $snapshot?->waitingStateCard
            ?? $this->waitingStateService->customer360Card($incident)
            ?? $this->waitingStateService->lifecycleOnlyCard($incident);

        $timeline = $snapshot?->timeline
            ?? ($order !== null ? $this->customer360TimelineService->forOrder($order) : null);

        $knowledge ??= $this->knowledgeEngine->forIncident($incident, $snapshot, $scopeCache);

        $customerSummary = $snapshot?->customerSummary ?? $scopeCache->customerSummary();
        $lastPayment = $order !== null && $timeline !== null
            ? $this->resolveLastPayment($order, $timeline)
            : null;
        $serialMissing = $order !== null && $this->isSerialMissing($order);
        $automationStatus = $this->automationStatusService->statusFor($incident)->label();
        $recentActivities = $this->recentActivities($incident);
        $internalRemarks = $this->internalRemarksMetrics($incident);
        $internalRemarksCount = $internalRemarks['count'];
        $internalRemarksSummary = $internalRemarks['summary'];

        $customerIntelligence = $this->knowledgeMapper->toCustomerIntelligence($knowledge);
        $deviceIntelligence = $this->knowledgeMapper->toDeviceIntelligence($knowledge);
        $businessIntelligence = $this->knowledgeMapper->toBusinessIntelligence($knowledge);
        $operationalIntelligence = $this->knowledgeMapper->toOperationalIntelligence(
            $knowledge,
            $incident,
            $waitingState,
            $automationStatus,
            $this->timelineSummary($timeline),
            $internalRemarksSummary,
            $this->queuePosition($incident),
        );

        $context = new AIContextDTO(
            incidentId: $incident->id,
            incidentReference: $incident->display_reference,
            incidentTitle: $incident->title,
            incidentDescription: $incident->description,
            incidentStatus: $incident->status->label(),
            incidentCategory: $incident->category,
            highPriority: (bool) $incident->high_priority,
            customerName: $order?->customer_name,
            customerPhone: $order?->customer_phone,
            customerEmail: $order?->customer_email,
            customerSummary: $customerSummary,
            orderId: $order?->order_id,
            serialNumber: $order?->serial_number,
            deviceModel: $order !== null ? DeviceModelFormatter::shortDisplay($order->displayDeviceModelName()) : null,
            activeServices: $activeServices,
            warrantyStatus: $warrantyStatus,
            lastPayment: $lastPayment,
            waitingState: $waitingState,
            orderHistory: $this->orderHistory($order, $incident),
            recentActivities: $recentActivities,
            automationHistory: $knowledge->operations->automationHistory,
            automationStatus: $automationStatus,
            serialMissing: $serialMissing,
            riskIndicators: [],
            customerIntelligence: $customerIntelligence,
            deviceIntelligence: $deviceIntelligence,
            operationalIntelligence: $operationalIntelligence,
            businessIntelligence: $businessIntelligence,
            internalRemarksCount: $internalRemarksCount,
            knowledge: $knowledge,
        );

        $riskIndicators = $this->riskScoringService->score($context);

        return new AIContextDTO(
            incidentId: $context->incidentId,
            incidentReference: $context->incidentReference,
            incidentTitle: $context->incidentTitle,
            incidentDescription: $context->incidentDescription,
            incidentStatus: $context->incidentStatus,
            incidentCategory: $context->incidentCategory,
            highPriority: $context->highPriority,
            customerName: $context->customerName,
            customerPhone: $context->customerPhone,
            customerEmail: $context->customerEmail,
            customerSummary: $context->customerSummary,
            orderId: $context->orderId,
            serialNumber: $context->serialNumber,
            deviceModel: $context->deviceModel,
            activeServices: $context->activeServices,
            warrantyStatus: $context->warrantyStatus,
            lastPayment: $context->lastPayment,
            waitingState: $context->waitingState,
            orderHistory: $context->orderHistory,
            recentActivities: $context->recentActivities,
            automationHistory: $context->automationHistory,
            automationStatus: $context->automationStatus,
            serialMissing: $context->serialMissing,
            riskIndicators: $riskIndicators,
            customerIntelligence: $context->customerIntelligence,
            deviceIntelligence: $context->deviceIntelligence,
            operationalIntelligence: $context->operationalIntelligence,
            businessIntelligence: $context->businessIntelligence,
            internalRemarksCount: $context->internalRemarksCount,
            knowledge: $context->knowledge,
        );
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
     * @return array{label: string, occurred_at: Carbon}|null
     */
    private function resolveLastPayment(Order $order, TimelineViewModel $timeline): ?array
    {
        $paymentEvent = $timeline->events()->first(
            fn ($event) => $event->type === TimelineEventType::Payment,
        );

        if ($paymentEvent !== null) {
            return [
                'label' => $paymentEvent->summary ?? $paymentEvent->title,
                'occurred_at' => $paymentEvent->occurredAt,
            ];
        }

        if ($order->payment_date === null) {
            return null;
        }

        $parts = [];

        if ($order->payment_amount !== null) {
            $parts[] = '₹'.number_format((float) $order->payment_amount, 2);
        }

        if (filled($order->payment_method)) {
            $parts[] = (string) $order->payment_method;
        }

        return [
            'label' => $parts === [] ? 'Payment received' : implode(' · ', $parts),
            'occurred_at' => $order->payment_date,
        ];
    }

    /**
     * @return list<array{title: string, type: string, occurred_at: Carbon}>
     */
    private function recentActivities(Incident $incident): array
    {
        return $this->activityTimelineService
            ->forIncident($incident)
            ->sortByDesc(fn ($entry) => $entry->occurredAt)
            ->take(self::RECENT_ACTIVITY_LIMIT)
            ->map(fn ($entry): array => [
                'title' => $entry->title,
                'type' => $entry->type,
                'occurred_at' => $entry->occurredAt,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{reference: string, title: string, status: string, created_at: Carbon|null}>
     */
    private function orderHistory(?Order $order, Incident $currentIncident): array
    {
        if ($order === null) {
            return [];
        }

        return Incident::query()
            ->where('order_id', $order->id)
            ->whereKeyNot($currentIncident->id)
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Incident $incident): array => [
                'reference' => $incident->display_reference,
                'title' => $incident->title,
                'status' => $incident->status->label(),
                'created_at' => $incident->created_at,
            ])
            ->all();
    }

    private function queuePosition(Incident $incident): ?int
    {
        if (! $incident->isPendingAdmin()) {
            return null;
        }

        $now = now();
        $rank = $incident->slaSortRank($now);
        $activeStatuses = array_map(
            fn (IncidentStatus $status) => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $ahead = Incident::query()
            ->select(['incidents.id', 'incidents.high_priority', 'incidents.created_at'])
            ->with([
                'order:id,transaction_id',
                'activeWaitingState:id,incident_id,sla_paused',
            ])
            ->whereNull('incidents.assigned_to_user_id')
            ->whereIn('incidents.status', $activeStatuses)
            ->where('incidents.id', '!=', $incident->id)
            ->whereHas('order', function ($query): void {
                $query->where(function ($builder): void {
                    $builder->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            })
            ->get()
            ->filter(fn (Incident $item) => $item->slaSortRank($now) < $rank)
            ->count();

        return $ahead + 1;
    }

    private function timelineSummary(?TimelineViewModel $timeline): string
    {
        if ($timeline === null || $timeline->totalCount === 0) {
            return 'No customer timeline events recorded.';
        }

        $latest = $timeline->events()->first();

        return $timeline->totalCount.' timeline event(s). Latest: '.($latest?->title ?? 'Unknown').'.';
    }

    /**
     * @return array{count: int, summary: string}
     */
    private function internalRemarksMetrics(Incident $incident): array
    {
        $rows = Remark::query()
            ->where('remarkable_type', $incident->getMorphClass())
            ->where('remarkable_id', $incident->id)
            ->selectRaw('body, COUNT(*) OVER() as total_count')
            ->orderByDesc('created_at')
            ->limit(self::REMARKS_SUMMARY_LIMIT)
            ->get();

        $count = (int) ($rows->first()?->total_count ?? 0);

        return [
            'count' => $count,
            'summary' => $this->formatInternalRemarksSummary($rows->pluck('body'), $count),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string|null>  $bodies
     */
    private function formatInternalRemarksSummary(\Illuminate\Support\Collection $bodies, int $count): string
    {
        if ($count === 0) {
            return 'No internal remarks recorded.';
        }

        $remarks = $bodies
            ->filter(fn (?string $body) => filled($body))
            ->map(fn (string $body) => Str::limit(trim($body), 80))
            ->values();

        if ($remarks->isEmpty()) {
            return 'No internal remarks recorded.';
        }

        return $remarks->count().' recent note(s): '.$remarks->implode(' | ');
    }

    private function isSerialMissing(Order $order): bool
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return false;
        }

        $serial = trim((string) $order->serial_number);

        return $serial === '' || $this->serialPlaceholderService->isPlaceholder($serial);
    }

    private function normalizeServiceStatus(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not Available';
        }

        return trim($value);
    }
}
