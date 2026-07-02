<?php

namespace App\Services\Knowledge;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\Knowledge\BusinessKnowledgeDTO;
use App\Data\Knowledge\CustomerKnowledgeDTO;
use App\Data\Knowledge\DeviceKnowledgeDTO;
use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Data\Knowledge\OperationsKnowledgeDTO;
use App\Data\Knowledge\RepairKnowledgeDTO;
use App\Data\TimelineViewModel;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KnowledgeEngine
{
    private const PART_KEYWORDS = ['replaced', 'part', 'board', 'sensor', 'battery', 'display'];

    public function __construct(
        private readonly SerialPlaceholderService $serialPlaceholderService,
    ) {}

    public function forIncident(
        Incident $incident,
        ?AIContextBuildSnapshot $snapshot = null,
        ?CustomerScopeQueryCache $scopeCache = null,
    ): KnowledgeResponseDTO {
        $incident->loadMissing(['order.deviceModel', 'assignee']);

        $order = $incident->order;
        $scopeCache ??= new CustomerScopeQueryCache($order?->customer_phone);
        $incidents = $scopeCache->incidentsWithAssignee();
        $aggregation = new KnowledgeAggregationCache($incidents, $incident);

        $customerSummary = $snapshot?->customerSummary ?? $scopeCache->customerSummary();
        $enrichmentMetadata = $snapshot?->enrichmentMetadata ?? [];
        $activeServices = $snapshot?->activeServices ?? [];
        $warrantyStatus = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? 'Not Available';
        $timeline = $snapshot?->timeline;

        $customer = $this->buildCustomerKnowledge(
            $scopeCache,
            $incidents,
            $incident,
            $customerSummary,
            $warrantyStatus,
            $timeline,
            $aggregation,
        );

        $serialMissing = $order !== null && $this->isSerialMissing($order);

        $device = $this->buildDeviceKnowledge(
            $order,
            $incident,
            $scopeCache,
            $incidents,
            $serialMissing,
        );

        $repair = $this->buildRepairKnowledge($incident, $incidents, $scopeCache, $aggregation);

        $business = $this->buildBusinessKnowledge($scopeCache, $activeServices, $enrichmentMetadata, $customerSummary);

        $operations = $this->buildOperationsKnowledge($incident);

        $knowledgeSummary = $this->buildKnowledgeSummary($repair, $device, $customer);

        return new KnowledgeResponseDTO(
            customer: $customer,
            device: $device,
            repair: $repair,
            business: $business,
            operations: $operations,
            knowledgeSummary: $knowledgeSummary,
        );
    }

    private function buildCustomerKnowledge(
        CustomerScopeQueryCache $scopeCache,
        Collection $incidents,
        Incident $current,
        array $customerSummary,
        string $warrantyStatus,
        ?TimelineViewModel $timeline,
        KnowledgeAggregationCache $aggregation,
    ): CustomerKnowledgeDTO {
        $repeatIssue = $aggregation->repeatIssue();
        $isPremium = ($customerSummary['total_orders'] ?? 0) >= 2
            || ($customerSummary['total_devices'] ?? 0) >= 2;

        $closed = $aggregation->closedIncidents();
        $repeatPercentage = $aggregation->repeatFailurePercentage();
        $outstandingBalance = $this->outstandingBalance($scopeCache);

        return new CustomerKnowledgeDTO(
            lifetimeOrderCount: $customerSummary['total_orders'] ?? 0,
            lifetimeRepairCount: $incidents->count(),
            isPremiumCustomer: $isPremium,
            previousIncidents: $this->mapPreviousIncidents($incidents, $current),
            previousRepairs: $this->mapPreviousRepairs($closed),
            previousPayments: $this->mapPreviousPayments($scopeCache),
            previousEscalations: $this->mapPreviousEscalations($incidents, $current),
            repeatComplaints: $this->mapRepeatComplaints($incidents, $current),
            repeatIssueDetected: $repeatIssue['detected'],
            repeatIssueSummary: $repeatIssue['summary'],
            repeatIssuePercentage: $repeatPercentage,
            averageRepairTurnaroundDays: $aggregation->averageRepairTurnaroundDays(),
            lastInteractionAt: $this->resolveLastInteractionAt($timeline),
            lastInteractionSummary: $this->resolveLastInteractionSummary($timeline),
            outstandingBalance: $outstandingBalance,
            paymentBehaviour: $this->paymentBehaviour($scopeCache, $outstandingBalance),
            warrantyHistorySummary: $this->warrantyHistorySummary($warrantyStatus, $customerSummary),
        );
    }

    private function buildDeviceKnowledge(
        ?Order $order,
        Incident $incident,
        CustomerScopeQueryCache $scopeCache,
        Collection $incidents,
        bool $serialMissing,
    ): DeviceKnowledgeDTO {
        if ($order === null) {
            return new DeviceKnowledgeDTO(
                model: null,
                category: $incident->category,
                variant: null,
                serialAvailable: false,
                previousRepairsOnSerial: 0,
                previousRepairsOnModel: 0,
                repairHistory: [],
                failureHistory: [],
                partsReplaced: [],
                technicianHistory: [],
                serialHistory: [],
            );
        }

        $modelName = $order->displayDeviceModelName();
        $modelKey = Str::lower(trim((string) $modelName));
        $serial = trim((string) $order->serial_number);

        $repairsOnSerial = $serial !== '' && ! $serialMissing
            ? $incidents->filter(fn (Incident $item) => $item->id !== $incident->id && $item->order_id === $order->id)->count()
            : 0;

        $repairsOnModel = $incidents->filter(function (Incident $item) use ($order, $modelKey, $incident, $scopeCache): bool {
            if ($item->id === $incident->id) {
                return false;
            }

            $itemOrder = $item->order_id === $order->id
                ? $order
                : $scopeCache->orders()->firstWhere('id', $item->order_id);

            return $itemOrder !== null
                && Str::lower(trim((string) $itemOrder->displayDeviceModelName())) === $modelKey;
        })->count();

        $orderIncidents = $incidents->where('order_id', $order->id);

        return new DeviceKnowledgeDTO(
            model: DeviceModelFormatter::shortDisplay($modelName),
            category: $order->deviceModel?->brand ?? $incident->category,
            variant: $modelName,
            serialAvailable: ! $serialMissing,
            previousRepairsOnSerial: $repairsOnSerial,
            previousRepairsOnModel: $repairsOnModel,
            repairHistory: $orderIncidents
                ->where('id', '!=', $incident->id)
                ->map(fn (Incident $item): array => [
                    'reference' => $item->display_reference,
                    'title' => $item->title,
                    'status' => $item->status->label(),
                ])
                ->values()
                ->all(),
            failureHistory: $this->failureHistory($incidents, $modelKey, $incident),
            partsReplaced: $this->partsFromIncidentIds($incidents->pluck('id')->all()),
            technicianHistory: $this->technicianHistory($incidents, $incident),
            serialHistory: $scopeCache->orders()
                ->filter(fn (Order $item) => filled(trim((string) $item->serial_number)))
                ->map(fn (Order $item): array => [
                    'serial' => (string) $item->serial_number,
                    'order_id' => (string) $item->order_id,
                ])
                ->unique('serial')
                ->values()
                ->all(),
        );
    }

    private function buildRepairKnowledge(
        Incident $incident,
        Collection $incidents,
        CustomerScopeQueryCache $scopeCache,
        KnowledgeAggregationCache $aggregation,
    ): RepairKnowledgeDTO {
        $closed = $aggregation->closedIncidents();
        $modelStats = $this->modelWiseRepairStatistics($incidents, $scopeCache);
        $previousTechnician = $this->previousTechnician($incidents, $incident);

        return new RepairKnowledgeDTO(
            similarIncidentCount: $aggregation->similarIncidentCount(),
            mostCommonResolution: $aggregation->mostCommonResolution(),
            averageResolutionTimeDays: $aggregation->averageRepairTurnaroundDays(),
            historicalSuccessRate: $aggregation->historicalSuccessRate(),
            repeatFailurePercentage: $aggregation->repeatFailurePercentage(),
            previousTechnician: $previousTechnician,
            commonFixes: $aggregation->topRecommendedFixes(),
            successfulResolutions: $closed->take(5)->map(fn (Incident $item) => $item->title.' → '.$item->status->label())->values()->all(),
            repeatFailures: $aggregation->repeatIssue()['detected']
                ? [$aggregation->repeatIssue()['summary'] ?? 'Repeat failure detected']
                : [],
            averageRepairDurationDays: $aggregation->averageRepairTurnaroundDays(),
            modelWiseRepairStatistics: $modelStats,
            topRecommendedFixes: $aggregation->topRecommendedFixes(),
        );
    }

    /**
     * @param  list<array{label: string, status: string, variant: string}>  $activeServices
     * @param  array<string, mixed>  $enrichmentMetadata
     * @param  array<string, int>  $customerSummary
     */
    private function buildBusinessKnowledge(
        CustomerScopeQueryCache $scopeCache,
        array $activeServices,
        array $enrichmentMetadata,
        array $customerSummary,
    ): BusinessKnowledgeDTO {
        $orders = $scopeCache->orders();
        $revenue = (float) $orders->sum(fn (Order $order) => (float) ($order->payment_amount ?? 0));
        $repeatRevenue = (float) $orders
            ->filter(fn (Order $order) => $order->payment_date !== null)
            ->skip(1)
            ->sum(fn (Order $order) => (float) ($order->payment_amount ?? 0));
        $amc = collect($activeServices)->firstWhere('label', 'AMC')['status'] ?? 'Not Available';

        return new BusinessKnowledgeDTO(
            customerLifetimeValue: $revenue,
            profitability: max(0, $revenue),
            warrantyCost: 0.0,
            repeatRevenue: $repeatRevenue,
            totalRepairValue: $revenue,
            partsCostHistory: 0.0,
            amcHistory: $amc === 'Not Available'
                ? []
                : [['plan' => 'AMC', 'status' => $amc]],
        );
    }

    private function buildOperationsKnowledge(Incident $incident): OperationsKnowledgeDTO
    {
        $waitingStates = IncidentWaitingState::query()
            ->where('incident_id', $incident->id)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        $waitingStateIds = $waitingStates->pluck('id');

        $automationHistory = $waitingStateIds->isEmpty()
            ? []
            : AutomationExecution::query()
                ->whereIn('waiting_state_id', $waitingStateIds)
                ->latest('started_at')
                ->limit(10)
                ->get()
                ->map(fn (AutomationExecution $execution): array => [
                    'policy_key' => $execution->policy_key,
                    'action_type' => $execution->action_type->value,
                    'status' => $execution->status->value,
                    'occurred_at' => $execution->started_at ?? $execution->created_at,
                ])
                ->all();

        return new OperationsKnowledgeDTO(
            slaHistory: $waitingStates->map(fn (IncidentWaitingState $state): array => [
                'state' => $state->sla_paused ? 'Paused' : 'Active',
                'label' => $state->waiting_reason->label(),
                'started_at' => $state->started_at,
                'cleared_at' => $state->cleared_at,
            ])->all(),
            automationHistory: $automationHistory,
            notificationHistory: $this->notificationHistory($incident),
            waitingStateHistory: $waitingStates->map(fn (IncidentWaitingState $state): array => [
                'reason' => $state->waiting_reason->label(),
                'started_at' => $state->started_at,
                'cleared_at' => $state->cleared_at,
            ])->all(),
        );
    }

    private function buildKnowledgeSummary(
        RepairKnowledgeDTO $repair,
        DeviceKnowledgeDTO $device,
        CustomerKnowledgeDTO $customer,
    ): string {
        $parts = [];

        if ($repair->similarIncidentCount > 0) {
            $parts[] = $repair->similarIncidentCount.' similar repair(s) on record.';
        }

        if ($repair->mostCommonResolution !== null) {
            $parts[] = 'Most common resolution: '.$repair->mostCommonResolution.'.';
        }

        if ($repair->averageResolutionTimeDays !== null) {
            $parts[] = 'Average resolution: '.$repair->averageResolutionTimeDays.' days.';
        }

        if ($repair->previousTechnician !== null) {
            $parts[] = 'Previous engineer: '.$repair->previousTechnician.'.';
        }

        if ($customer->repeatIssueDetected) {
            $parts[] = 'Repeat failure rate: '.$customer->repeatIssuePercentage.'%.';
        }

        if ($parts === []) {
            return 'Limited historical knowledge available for this case.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array{reference: string, title: string, status: string, created_at: Carbon|null}>
     */
    private function mapPreviousIncidents(Collection $incidents, Incident $current): array
    {
        return $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id)
            ->sortByDesc(fn (Incident $item) => $item->created_at)
            ->take(10)
            ->map(fn (Incident $item): array => [
                'reference' => $item->display_reference,
                'title' => $item->title,
                'status' => $item->status->label(),
                'created_at' => $item->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $closed
     * @return list<array{reference: string, title: string, status: string, resolved_at: Carbon|null}>
     */
    private function mapPreviousRepairs(Collection $closed): array
    {
        return $closed
            ->sortByDesc(fn (Incident $item) => $item->updated_at)
            ->take(10)
            ->map(fn (Incident $item): array => [
                'reference' => $item->display_reference,
                'title' => $item->title,
                'status' => $item->status->label(),
                'resolved_at' => $item->updated_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, amount: float|null, occurred_at: Carbon|null}>
     */
    private function mapPreviousPayments(CustomerScopeQueryCache $scopeCache): array
    {
        return $scopeCache->orders()
            ->filter(fn (Order $order) => $order->payment_date !== null)
            ->sortByDesc(fn (Order $order) => $order->payment_date)
            ->take(10)
            ->map(fn (Order $order): array => [
                'label' => $order->order_id,
                'amount' => $order->payment_amount !== null ? (float) $order->payment_amount : null,
                'occurred_at' => $order->payment_date,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array{reference: string, title: string, created_at: Carbon|null}>
     */
    private function mapPreviousEscalations(Collection $incidents, Incident $current): array
    {
        return $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id && $item->high_priority)
            ->sortByDesc(fn (Incident $item) => $item->created_at)
            ->take(5)
            ->map(fn (Incident $item): array => [
                'reference' => $item->display_reference,
                'title' => $item->title,
                'created_at' => $item->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<string>
     */
    private function mapRepeatComplaints(Collection $incidents, Incident $current): array
    {
        return $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id)
            ->groupBy(fn (Incident $item) => strtolower(trim($item->title)))
            ->filter(fn ($group) => $group->count() >= 1)
            ->keys()
            ->take(5)
            ->map(fn (string $title) => ucfirst($title))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<string>
     */
    private function failureHistory(Collection $incidents, string $modelKey, Incident $current): array
    {
        if ($modelKey === '') {
            return [];
        }

        return $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id)
            ->groupBy(fn (Incident $item) => strtolower(trim($item->title)))
            ->sortByDesc(fn ($group) => $group->count())
            ->take(3)
            ->keys()
            ->map(fn (string $title) => ucfirst($title))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $incidentIds
     * @return list<string>
     */
    private function partsFromIncidentIds(array $incidentIds): array
    {
        if ($incidentIds === []) {
            return [];
        }

        return Remark::query()
            ->where('remarkable_type', (new Incident)->getMorphClass())
            ->whereIn('remarkable_id', $incidentIds)
            ->latest('created_at')
            ->limit(30)
            ->pluck('body')
            ->flatMap(function (?string $body): array {
                if (! filled($body)) {
                    return [];
                }

                $found = [];
                $lower = Str::lower($body);

                foreach (self::PART_KEYWORDS as $keyword) {
                    if (Str::contains($lower, $keyword)) {
                        $found[] = $keyword;
                    }
                }

                return $found;
            })
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->keys()
            ->map(fn (string $keyword) => ucfirst($keyword))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array{technician: string, reference: string, occurred_at: Carbon|null}>
     */
    private function technicianHistory(Collection $incidents, Incident $current): array
    {
        return $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id && $item->assignee !== null)
            ->sortByDesc(fn (Incident $item) => $item->updated_at)
            ->take(5)
            ->map(fn (Incident $item): array => [
                'technician' => (string) $item->assignee?->name,
                'reference' => $item->display_reference,
                'occurred_at' => $item->updated_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return array<string, int>
     */
    private function modelWiseRepairStatistics(Collection $incidents, CustomerScopeQueryCache $scopeCache): array
    {
        $stats = [];

        foreach ($incidents as $incident) {
            $order = $scopeCache->orders()->firstWhere('id', $incident->order_id);

            if ($order === null) {
                continue;
            }

            $model = DeviceModelFormatter::shortDisplay($order->displayDeviceModelName()) ?: 'Unknown';
            $stats[$model] = ($stats[$model] ?? 0) + 1;
        }

        arsort($stats);

        return $stats;
    }

    private function previousTechnician(Collection $incidents, Incident $current): ?string
    {
        $previous = $incidents
            ->filter(fn (Incident $item) => $item->id !== $current->id && $item->assignee !== null)
            ->sortByDesc(fn (Incident $item) => $item->updated_at)
            ->first();

        return $previous?->assignee?->name;
    }

    /**
     * @return list<array{channel: string, status: string, occurred_at: Carbon|null}>
     */
    private function notificationHistory(Incident $incident): array
    {
        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', 'like', '%notification%')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'channel' => (string) ($log->new_values['channel'] ?? 'unknown'),
                'status' => (string) ($log->new_values['status'] ?? $log->event),
                'occurred_at' => $log->created_at,
            ])
            ->all();
    }

    private function resolveLastInteractionAt(?TimelineViewModel $timeline): ?Carbon
    {
        return $timeline?->events()->first()?->occurredAt;
    }

    private function resolveLastInteractionSummary(?TimelineViewModel $timeline): ?string
    {
        return $timeline?->events()->first()?->title;
    }

    private function outstandingBalance(CustomerScopeQueryCache $scopeCache): float
    {
        $orderIds = $scopeCache->orderIds();

        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        return (float) RefundRequest::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', RefundStatus::Pending)
            ->sum('amount');
    }

    private function paymentBehaviour(CustomerScopeQueryCache $scopeCache, float $outstandingBalance): string
    {
        if ($outstandingBalance > 0) {
            return 'Outstanding refund balance ₹'.number_format($outstandingBalance, 2);
        }

        $paidOrders = $scopeCache->orders()->filter(fn (Order $order) => $order->payment_date !== null)->count();
        $totalOrders = $scopeCache->orders()->count();

        if ($paidOrders === 0) {
            return 'No payments recorded';
        }

        if ($paidOrders === $totalOrders) {
            return 'Consistent payer';
        }

        return $paidOrders.' of '.$totalOrders.' orders paid';
    }

    /**
     * @param  array<string, int>  $customerSummary
     */
    private function warrantyHistorySummary(string $currentWarranty, array $customerSummary): string
    {
        $premium = ($customerSummary['total_orders'] ?? 0) >= 2 ? 'Multi-device customer. ' : '';

        return $premium.'Current warranty: '.$currentWarranty.'.';
    }

    private function isSerialMissing(Order $order): bool
    {
        $serial = trim((string) $order->serial_number);

        return $serial === '' || $this->serialPlaceholderService->isPlaceholder($serial);
    }
}
