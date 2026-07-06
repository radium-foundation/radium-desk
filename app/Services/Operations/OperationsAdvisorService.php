<?php

namespace App\Services\Operations;

use App\Data\AI\AIContextBuildSnapshot;
use App\Data\AI\AIIncidentBundle;
use App\Data\Operations\OperationsInsightDTO;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\AI\AIRiskLevel;
use App\Enums\Operations\OperationsInsightCategory;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AI\CustomerScopeQueryCache;
use App\Services\Knowledge\KnowledgeAggregationCache;
use App\Services\Knowledge\KnowledgeEngine;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OperationsAdvisorService
{
    private const PLATFORM_CACHE_KEY = 'operations:advisor:platform';

    private const CACHE_TTL_SECONDS = 60;

    private const ENGINEER_OVERLOAD_MIN_CASES = 8;

    private const ENGINEER_OVERLOAD_RATIO = 1.5;

    private const QUEUE_CONGESTION_PENDING = 25;

    private const AUTOMATION_FAILURE_THRESHOLD = 3;

    public function __construct(
        private readonly OperationsDashboardService $dashboardService,
        private readonly KnowledgeEngine $knowledgeEngine,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
    ) {}

    /**
     * @return list<OperationsInsightDTO>
     */
    public function platformInsights(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = Cache::get(self::PLATFORM_CACHE_KEY);

            if (is_array($cached) && $this->isCachedInsightList($cached)) {
                return $cached;
            }
        }

        $insights = $this->buildPlatformInsights();

        Cache::put(self::PLATFORM_CACHE_KEY, $insights, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    public function incidentInsights(
        Incident $incident,
        ?AIContextBuildSnapshot $snapshot = null,
    ): array {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);

        if ($incident->order === null) {
            return [];
        }

        $scopeCache = new CustomerScopeQueryCache($incident->order->customer_phone);
        $knowledge = $this->knowledgeEngine->forIncident($incident, $snapshot, $scopeCache);

        return $this->buildIncidentInsights(
            $incident,
            $knowledge,
            $scopeCache->incidentsWithAssignee(),
            $snapshot,
        );
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    public function incidentInsightsFromBundle(
        Incident $incident,
        AIIncidentBundle $bundle,
        ?AIContextBuildSnapshot $snapshot = null,
    ): array {
        $incident->loadMissing(['order.deviceModel', 'activeWaitingState', 'assignee']);

        if ($incident->order === null) {
            return [];
        }

        return $this->buildIncidentInsights(
            $incident,
            $bundle->knowledge,
            $bundle->scopeCache->incidentsWithAssignee(),
            $snapshot,
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<OperationsInsightDTO>
     */
    private function buildIncidentInsights(
        Incident $incident,
        \App\Data\Knowledge\KnowledgeResponseDTO $knowledge,
        Collection $incidents,
        ?AIContextBuildSnapshot $snapshot = null,
    ): array {
        $aggregation = new KnowledgeAggregationCache($incidents, $incident);
        $repeatIssue = $aggregation->repeatIssue();
        $customerSummary = $snapshot?->customerSummary ?? (new CustomerScopeQueryCache($incident->order?->customer_phone))->customerSummary();
        $activeServices = $snapshot?->activeServices ?? [];
        $enrichmentMetadata = $snapshot?->enrichmentMetadata ?? $this->enrichmentSyncStore->metadata($incident->order_id) ?? [];

        $insights = [];

        $slaStatus = $incident->slaStatus();
        if ($slaStatus === ServiceCaseSlaStatus::Overdue
            || ($incident->high_priority && $slaStatus === ServiceCaseSlaStatus::Warning)) {
            $insights[] = new OperationsInsightDTO(
                title: 'High SLA Risk',
                category: OperationsInsightCategory::SlaRisk,
                severity: AIRiskLevel::High,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 92,
                recommendation: 'Prioritize resolution and update the customer on expected turnaround.',
                affectedIncidents: [$this->incidentReference($incident)],
                affectedCustomers: [$this->customerReference($incident->order)],
                supportingMetrics: [
                    'sla_state' => $slaStatus->label(),
                    'pending_hours' => $incident->created_at !== null
                        ? (int) $incident->created_at->diffInHours(now())
                        : null,
                    'high_priority' => (bool) $incident->high_priority,
                ],
                actionUrl: route('incidents.show', $incident),
            );
        }

        if ($repeatIssue['detected']) {
            $insights[] = new OperationsInsightDTO(
                title: 'Repeat Failure Risk',
                category: OperationsInsightCategory::CustomerRisk,
                severity: AIRiskLevel::High,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 88,
                recommendation: 'Review prior repair history before repeating the same fix path.',
                affectedIncidents: [$this->incidentReference($incident)],
                affectedCustomers: [$this->customerReference($incident->order)],
                supportingMetrics: [
                    'repeat_issue_summary' => $repeatIssue['summary'],
                    'lifetime_repairs' => $incidents->count(),
                    'repeat_failure_percent' => $aggregation->repeatFailurePercentage(),
                ],
                actionUrl: route('dashboard.service-cases.customer-360', $incident),
            );
        }

        if ($this->isAmcOpportunity($activeServices, $enrichmentMetadata)) {
            $insights[] = new OperationsInsightDTO(
                title: 'AMC Opportunity',
                category: OperationsInsightCategory::RevenueOpportunity,
                severity: AIRiskLevel::Low,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 74,
                recommendation: 'Discuss annual maintenance coverage during the next customer touchpoint.',
                affectedIncidents: [$this->incidentReference($incident)],
                affectedCustomers: [$this->customerReference($incident->order)],
                supportingMetrics: [
                    'warranty_status' => collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? 'Not Available',
                    'amc_status' => collect($activeServices)->firstWhere('label', 'AMC')['status'] ?? 'Not Available',
                    'lifetime_orders' => $customerSummary['total_orders'] ?? 0,
                ],
                actionUrl: route('dashboard.service-cases.customer-360', $incident),
            );
        }

        if ($incident->high_priority
            || (($customerSummary['total_orders'] ?? 0) >= 2 && $slaStatus !== ServiceCaseSlaStatus::WithinSla)) {
            $insights[] = new OperationsInsightDTO(
                title: 'Escalation Risk',
                category: OperationsInsightCategory::CustomerRisk,
                severity: $incident->high_priority ? AIRiskLevel::High : AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: $incident->high_priority ? 85 : 70,
                recommendation: 'Assign a senior engineer and confirm proactive customer communication.',
                affectedIncidents: [$this->incidentReference($incident)],
                affectedCustomers: [$this->customerReference($incident->order)],
                supportingMetrics: [
                    'high_priority' => (bool) $incident->high_priority,
                    'sla_state' => $slaStatus->label(),
                    'premium_customer' => ($customerSummary['total_orders'] ?? 0) >= 2,
                    'open_cases' => $customerSummary['open_cases'] ?? 0,
                ],
                actionUrl: route('incidents.show', $incident),
            );
        }

        return $this->sortInsights($insights);
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function buildPlatformInsights(): array
    {
        $snapshot = new OperationsAdvisorSnapshot(
            $this->dashboardService->dashboardData(),
            $this->enrichmentSyncStore,
        );
        $insights = [
            ...$this->analyzeSlaRisk($snapshot),
            ...$this->analyzeCustomerRisk($snapshot),
            ...$this->analyzeAutomationHealth($snapshot),
            ...$this->analyzeNotificationHealth($snapshot),
            ...$this->analyzeEngineerWorkload($snapshot),
            ...$this->analyzeRevenueOpportunities($snapshot),
        ];

        return $this->sortInsights($insights);
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeSlaRisk(OperationsAdvisorSnapshot $snapshot): array
    {
        $counts = $snapshot->slaCounts();
        $serviceOverdue = $counts['service_overdue_cases'] ?? $counts['overdue_cases'];
        $serviceWarning = $counts['service_warning_cases'] ?? $counts['warning_cases'];
        $likelyBreaches = $serviceOverdue + $serviceWarning;
        $insights = [];

        if ($likelyBreaches > 0) {
            $pending = $snapshot->pendingAdminIncidents()
                ->filter(fn (Incident $incident): bool => ! \App\Models\Order::isHardwareOrderId($incident->order?->order_id));
            $now = now();
            $affected = $pending
                ->filter(fn (Incident $incident): bool => in_array(
                    $incident->slaStatus($now)->value,
                    [ServiceCaseSlaStatus::Overdue->value, ServiceCaseSlaStatus::Warning->value],
                    true,
                ))
                ->take(10)
                ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                ->values()
                ->all();

            $insights[] = new OperationsInsightDTO(
                title: $likelyBreaches === 1
                    ? '1 SLA breach likely today'
                    : "{$likelyBreaches} SLA breaches likely today",
                category: OperationsInsightCategory::SlaRisk,
                severity: $serviceOverdue > 0 ? AIRiskLevel::High : AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 90,
                recommendation: 'Review overdue and warning cases first and reassign if queues are blocked.',
                affectedIncidents: $affected,
                affectedCustomers: $this->customersFromIncidents($pending, $now, [
                    ServiceCaseSlaStatus::Overdue,
                    ServiceCaseSlaStatus::Warning,
                ]),
                supportingMetrics: $counts,
                actionUrl: route('dashboard', ['queue' => $counts['overdue_cases'] > 0 ? 'attention' : 'attention']),
            );
        }

        $longWaiting = $snapshot->longWaitingStates();
        if ($longWaiting->isNotEmpty()) {
            $insights[] = new OperationsInsightDTO(
                title: $longWaiting->count() === 1
                    ? '1 case in a long waiting state'
                    : "{$longWaiting->count()} cases in long waiting states",
                category: OperationsInsightCategory::SlaRisk,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 78,
                recommendation: 'Follow up on stalled waiting states and clear blockers or update SLA notes.',
                affectedIncidents: $longWaiting
                    ->take(10)
                    ->map(fn ($state): array => $this->incidentReference($state->incident))
                    ->values()
                    ->all(),
                affectedCustomers: $longWaiting
                    ->take(10)
                    ->map(fn ($state): array => $this->customerReference($state->incident->order))
                    ->filter()
                    ->unique('phone')
                    ->values()
                    ->all(),
                supportingMetrics: [
                    'long_waiting_count' => $longWaiting->count(),
                    'minimum_days' => 3,
                ],
                actionUrl: route('dashboard', ['queue' => 'attention']),
            );
        }

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeCustomerRisk(OperationsAdvisorSnapshot $snapshot): array
    {
        $insights = [];
        $repeatCustomers = $snapshot->repeatComplaintCustomers();

        if ($repeatCustomers->isNotEmpty()) {
            $count = $repeatCustomers->count();
            $insights[] = new OperationsInsightDTO(
                title: $count === 1
                    ? '1 customer with repeat complaints'
                    : "{$count} customers with repeat complaints",
                category: OperationsInsightCategory::CustomerRisk,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 84,
                recommendation: 'Prioritize repeat-complaint customers and review prior resolutions.',
                affectedIncidents: $repeatCustomers
                    ->flatMap(fn (Collection $incidents) => $incidents)
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: $repeatCustomers
                    ->map(fn (Collection $incidents, string $phone): array => [
                        'name' => $incidents->first()?->order?->customer_name,
                        'phone' => $phone,
                        'open_cases' => $incidents->count(),
                    ])
                    ->values()
                    ->all(),
                supportingMetrics: [
                    'repeat_customer_count' => $count,
                ],
                actionUrl: route('dashboard', ['queue' => 'attention']),
            );
        }

        $premiumWaiting = $snapshot->premiumCustomersWaiting();
        if ($premiumWaiting->isNotEmpty()) {
            $worst = $premiumWaiting->sortByDesc(
                fn (Incident $incident): int => $incident->created_at !== null
                    ? (int) $incident->created_at->diffInDays(now())
                    : 0,
            )->first();

            $waitingDays = $worst?->created_at !== null
                ? (int) $worst->created_at->diffInDays(now())
                : 0;
            $customerName = $worst?->order?->customer_name ?? 'Premium customer';

            $insights[] = new OperationsInsightDTO(
                title: "Premium customer waiting {$waitingDays} day".($waitingDays === 1 ? '' : 's'),
                category: OperationsInsightCategory::CustomerRisk,
                severity: $waitingDays >= 3 ? AIRiskLevel::High : AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 86,
                recommendation: 'Provide priority handling and proactive status updates.',
                affectedIncidents: $premiumWaiting
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: [[
                    'name' => $customerName,
                    'phone' => $worst?->order?->customer_phone,
                    'waiting_days' => $waitingDays,
                ]],
                supportingMetrics: [
                    'premium_waiting_count' => $premiumWaiting->count(),
                    'longest_wait_days' => $waitingDays,
                ],
                actionUrl: route('dashboard', ['queue' => 'action_required']),
            );
        }

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeAutomationHealth(OperationsAdvisorSnapshot $snapshot): array
    {
        $insights = [];
        $metrics = $snapshot->dashboard->automationMetrics;
        $queueMetrics = $snapshot->dashboard->queueMetrics;
        $failed = (int) ($metrics['failed'] ?? 0);
        $executions = (int) ($metrics['executions_today'] ?? 0);
        $retries = (int) ($queueMetrics['retries'] ?? 0);
        $pending = (int) ($queueMetrics['pending'] ?? 0);

        if ($failed >= self::AUTOMATION_FAILURE_THRESHOLD) {
            $recentFailures = collect($snapshot->dashboard->recentAutomationActivity)
                ->filter(fn (array $activity): bool => ($activity['result'] ?? '') === 'failed')
                ->take(5)
                ->values()
                ->all();

            $insights[] = new OperationsInsightDTO(
                title: "{$failed} automation failures today",
                category: OperationsInsightCategory::AutomationHealth,
                severity: AIRiskLevel::High,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 91,
                recommendation: 'Inspect failing automation policies and retry backlog after correcting configuration.',
                affectedIncidents: [],
                affectedCustomers: [],
                supportingMetrics: [
                    'failed_executions' => $failed,
                    'executions_today' => $executions,
                    'recent_failures' => $recentFailures,
                ],
                actionUrl: route('admin.operations.index').'#operations-recent-automation-activity',
            );
        }

        if ($retries >= 5 || $pending >= self::QUEUE_CONGESTION_PENDING) {
            $insights[] = new OperationsInsightDTO(
                title: $pending >= self::QUEUE_CONGESTION_PENDING
                    ? 'Queue congestion detected'
                    : 'High automation retry count',
                category: OperationsInsightCategory::AutomationHealth,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 76,
                recommendation: 'Check queue workers, failed jobs, and automation retry policies.',
                affectedIncidents: [],
                affectedCustomers: [],
                supportingMetrics: [
                    'pending_jobs' => $pending,
                    'retries' => $retries,
                    'failed_jobs' => (int) ($queueMetrics['failed'] ?? 0),
                ],
                actionUrl: route('admin.operations.index').'#operations-queue-metrics',
            );
        }

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeNotificationHealth(OperationsAdvisorSnapshot $snapshot): array
    {
        $insights = [];
        $metrics = $snapshot->dashboard->notificationMetrics;
        $channelTotals = $metrics['channel_totals'] ?? [];
        $whatsappFailed = (int) ($channelTotals['whatsapp']['failed'] ?? 0);
        $emailFailed = (int) ($channelTotals['email']['failed'] ?? 0);
        $whatsappSkipped = (int) ($channelTotals['whatsapp']['skipped'] ?? 0);
        $emailSkipped = (int) ($channelTotals['email']['skipped'] ?? 0);
        $successRate = $metrics['success_rate'];

        if ($whatsappFailed > 0) {
            $insights[] = new OperationsInsightDTO(
                title: 'WhatsApp delivery dropped',
                category: OperationsInsightCategory::NotificationHealth,
                severity: AIRiskLevel::High,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 93,
                recommendation: 'Review Interakt integration health and recent WhatsApp failure reasons.',
                affectedIncidents: $this->incidentsFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    'WhatsApp',
                ),
                affectedCustomers: $this->customersFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    'WhatsApp',
                ),
                supportingMetrics: [
                    'whatsapp_failed_today' => $whatsappFailed,
                    'whatsapp_sent_today' => (int) ($channelTotals['whatsapp']['sent'] ?? 0),
                ],
                actionUrl: route('admin.operations.index').'#operations-recent-notification-failures',
            );
        }

        if ($emailFailed > 0) {
            $insights[] = new OperationsInsightDTO(
                title: $emailFailed === 1
                    ? '1 email delivery failure today'
                    : "{$emailFailed} email delivery failures today",
                category: OperationsInsightCategory::NotificationHealth,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 88,
                recommendation: 'Verify customer email addresses and ZeptoMail integration status.',
                affectedIncidents: $this->incidentsFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    'Email',
                ),
                affectedCustomers: $this->customersFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    'Email',
                ),
                supportingMetrics: [
                    'email_failed_today' => $emailFailed,
                    'email_sent_today' => (int) ($channelTotals['email']['sent'] ?? 0),
                ],
                actionUrl: route('admin.operations.index').'#operations-recent-notification-failures',
            );
        }

        $missingEmailFailures = collect($snapshot->dashboard->recentNotificationFailures)
            ->filter(fn (array $failure): bool => Str::contains(
                Str::lower((string) ($failure['reason'] ?? '')),
                ['missing', 'email'],
            ))
            ->count();

        if ($missingEmailFailures > 0) {
            $insights[] = new OperationsInsightDTO(
                title: 'Missing customer email blocking delivery',
                category: OperationsInsightCategory::NotificationHealth,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 87,
                recommendation: 'Collect or update customer email addresses before retrying notifications.',
                affectedIncidents: $this->incidentsFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    null,
                    ['missing', 'email'],
                ),
                affectedCustomers: $this->customersFromNotificationFailures(
                    $snapshot->dashboard->recentNotificationFailures,
                    null,
                    ['missing', 'email'],
                ),
                supportingMetrics: [
                    'missing_email_failures' => $missingEmailFailures,
                ],
                actionUrl: route('admin.operations.index').'#operations-recent-notification-failures',
            );
        }

        if ($whatsappSkipped > 0 || $emailSkipped > 0) {
            $insights[] = new OperationsInsightDTO(
                title: 'Notification channel disabled or not configured',
                category: OperationsInsightCategory::NotificationHealth,
                severity: AIRiskLevel::Low,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 72,
                recommendation: 'Confirm channel configuration in system settings before expecting delivery.',
                affectedIncidents: [],
                affectedCustomers: [],
                supportingMetrics: [
                    'whatsapp_skipped_today' => $whatsappSkipped,
                    'email_skipped_today' => $emailSkipped,
                ],
                actionUrl: route('admin.operations.index').'#operations-integration-health',
            );
        }

        if ($successRate !== null && $successRate < 85.0 && ((int) ($metrics['failed_today'] ?? 0)) > 0) {
            $insights[] = new OperationsInsightDTO(
                title: 'Notification delivery degradation',
                category: OperationsInsightCategory::NotificationHealth,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 75,
                recommendation: 'Investigate declining success rate across notification channels.',
                affectedIncidents: [],
                affectedCustomers: [],
                supportingMetrics: [
                    'success_rate' => $successRate,
                    'failed_today' => (int) ($metrics['failed_today'] ?? 0),
                    'sent_today' => (int) ($metrics['sent_today'] ?? 0),
                ],
                actionUrl: route('admin.operations.index').'#operations-notification-metrics',
            );
        }

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeEngineerWorkload(OperationsAdvisorSnapshot $snapshot): array
    {
        $insights = [];
        $workloads = $snapshot->engineerWorkloads();

        if ($workloads->isEmpty()) {
            return [];
        }

        $counts = $workloads->map(fn (Collection $incidents): int => $incidents->count());
        $average = max(1, (int) round($counts->avg()));
        $threshold = max(self::ENGINEER_OVERLOAD_MIN_CASES, (int) ceil($average * self::ENGINEER_OVERLOAD_RATIO));

        $overloaded = $workloads
            ->filter(fn (Collection $incidents): bool => $incidents->count() >= $threshold)
            ->sortByDesc(fn (Collection $incidents): int => $incidents->count());

        if ($overloaded->isNotEmpty()) {
            /** @var Collection<int, Incident> $worstLoad */
            $worstLoad = $overloaded->first();
            $engineerName = $worstLoad->first()?->assignee?->name ?? 'Engineer';

            $insights[] = new OperationsInsightDTO(
                title: "Engineer {$engineerName} overloaded",
                category: OperationsInsightCategory::EngineerWorkload,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::High,
                confidenceScore: 82,
                recommendation: 'Rebalance assignments or pull in backup support for overloaded engineers.',
                affectedIncidents: $worstLoad
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: [],
                supportingMetrics: [
                    'assigned_cases' => $worstLoad->count(),
                    'team_average' => $average,
                    'overload_threshold' => $threshold,
                ],
                actionUrl: route('dashboard', ['queue' => 'my_work']),
            );
        }

        $staleAssignments = $snapshot->activeIncidents()
            ->filter(function (Incident $incident): bool {
                if ($incident->assigned_to_user_id === null || $incident->updated_at === null) {
                    return false;
                }

                return $incident->updated_at->lte(now()->subDays(5));
            });

        if ($staleAssignments->count() >= 3) {
            $insights[] = new OperationsInsightDTO(
                title: "{$staleAssignments->count()} long unresolved assignments",
                category: OperationsInsightCategory::EngineerWorkload,
                severity: AIRiskLevel::Medium,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 73,
                recommendation: 'Review stale assignments and confirm owners are actively progressing cases.',
                affectedIncidents: $staleAssignments
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: [],
                supportingMetrics: [
                    'stale_assignment_count' => $staleAssignments->count(),
                    'stale_days' => 5,
                ],
                actionUrl: route('dashboard', ['queue' => 'attention']),
            );
        }

        return $insights;
    }

    /**
     * @return list<OperationsInsightDTO>
     */
    private function analyzeRevenueOpportunities(OperationsAdvisorSnapshot $snapshot): array
    {
        $insights = [];
        $amcCandidates = $snapshot->amcEligibleCustomers();
        $repeatRepairCandidates = $snapshot->repeatRepairCandidates();
        $expiredWarrantyCases = $snapshot->expiredWarrantyOpenCases();
        $expiredWarrantyCount = $expiredWarrantyCases->count();

        if ($amcCandidates->isNotEmpty()) {
            $count = $amcCandidates->count();
            $insights[] = new OperationsInsightDTO(
                title: "{$count} AMC opportunit".($count === 1 ? 'y' : 'ies'),
                category: OperationsInsightCategory::RevenueOpportunity,
                severity: AIRiskLevel::Low,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 70,
                recommendation: 'Engage eligible customers about annual maintenance plans during service updates.',
                affectedIncidents: $amcCandidates
                    ->flatMap(fn (Collection $incidents) => $incidents)
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: $amcCandidates
                    ->map(fn (Collection $incidents, string $phone): array => [
                        'name' => $incidents->first()?->order?->customer_name,
                        'phone' => $phone,
                    ])
                    ->values()
                    ->all(),
                supportingMetrics: [
                    'amc_opportunity_count' => $count,
                ],
                actionUrl: route('dashboard', ['queue' => 'action_required']),
            );
        }

        if ($expiredWarrantyCount > 0) {
            $insights[] = new OperationsInsightDTO(
                title: $expiredWarrantyCount === 1
                    ? '1 open case with expired warranty'
                    : "{$expiredWarrantyCount} open cases with expired warranty",
                category: OperationsInsightCategory::RevenueOpportunity,
                severity: AIRiskLevel::Low,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 68,
                recommendation: 'Discuss paid repair or upgrade options with warranty-expired customers.',
                affectedIncidents: $expiredWarrantyCases
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: $expiredWarrantyCases
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->customerReference($incident->order))
                    ->filter()
                    ->values()
                    ->all(),
                supportingMetrics: [
                    'expired_warranty_open_cases' => $expiredWarrantyCount,
                ],
                actionUrl: route('dashboard', ['queue' => 'action_required']),
            );
        }

        if ($repeatRepairCandidates->isNotEmpty()) {
            $count = $repeatRepairCandidates->count();
            $insights[] = new OperationsInsightDTO(
                title: $count === 1
                    ? '1 repeat repair candidate'
                    : "{$count} repeat repair candidates",
                category: OperationsInsightCategory::RevenueOpportunity,
                severity: AIRiskLevel::Low,
                confidence: AIConfidenceLevel::Medium,
                confidenceScore: 71,
                recommendation: 'Offer proactive service review or upgrade consultation for repeat repair customers.',
                affectedIncidents: $repeatRepairCandidates
                    ->flatMap(fn (Collection $incidents) => $incidents)
                    ->take(10)
                    ->map(fn (Incident $incident): array => $this->incidentReference($incident))
                    ->values()
                    ->all(),
                affectedCustomers: $repeatRepairCandidates
                    ->map(fn (Collection $incidents, string $phone): array => [
                        'name' => $incidents->first()?->order?->customer_name,
                        'phone' => $phone,
                        'repair_count' => $incidents->count(),
                    ])
                    ->values()
                    ->all(),
                supportingMetrics: [
                    'repeat_repair_customer_count' => $count,
                ],
                actionUrl: route('dashboard', ['queue' => 'attention']),
            );
        }

        return $insights;
    }

    /**
     * @param  list<OperationsInsightDTO>  $insights
     * @return list<OperationsInsightDTO>
     */
    private function sortInsights(array $insights): array
    {
        usort($insights, function (OperationsInsightDTO $left, OperationsInsightDTO $right): int {
            $severityRank = fn (AIRiskLevel $level): int => match ($level) {
                AIRiskLevel::High => 0,
                AIRiskLevel::Medium => 1,
                AIRiskLevel::Low => 2,
            };

            $severityComparison = $severityRank($left->severity) <=> $severityRank($right->severity);

            if ($severityComparison !== 0) {
                return $severityComparison;
            }

            return $right->confidenceScore <=> $left->confidenceScore;
        });

        return $insights;
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentReference(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'reference' => $incident->display_reference,
            'url' => route('incidents.show', $incident),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerReference(?Order $order): array
    {
        if ($order === null) {
            return [];
        }

        return [
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'email' => $order->customer_email,
        ];
    }

    /**
     * @param  list<ServiceCaseSlaStatus>  $statuses
     * @return list<array<string, mixed>>
     */
    private function customersFromIncidents(
        Collection $incidents,
        Carbon $now,
        array $statuses,
    ): array {
        return $incidents
            ->filter(fn (Incident $incident): bool => in_array($incident->slaStatus($now), $statuses, true))
            ->map(fn (Incident $incident): array => $this->customerReference($incident->order))
            ->filter(fn (array $customer): bool => $customer !== [])
            ->unique('phone')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @param  list<string>|null  $reasonNeedles
     * @return list<array<string, mixed>>
     */
    private function incidentsFromNotificationFailures(
        array $failures,
        ?string $channel = null,
        ?array $reasonNeedles = null,
    ): array {
        return collect($failures)
            ->filter(function (array $failure) use ($channel, $reasonNeedles): bool {
                if ($channel !== null && ($failure['channel'] ?? '') !== $channel) {
                    return false;
                }

                if ($reasonNeedles !== null) {
                    $reason = Str::lower((string) ($failure['reason'] ?? ''));

                    return collect($reasonNeedles)->contains(
                        fn (string $needle): bool => Str::contains($reason, Str::lower($needle)),
                    );
                }

                return true;
            })
            ->filter(fn (array $failure): bool => filled($failure['incident_reference'] ?? null))
            ->take(10)
            ->map(fn (array $failure): array => [
                'reference' => $failure['incident_reference'],
                'url' => $failure['incident_url'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @param  list<string>|null  $reasonNeedles
     * @return list<array<string, mixed>>
     */
    private function customersFromNotificationFailures(
        array $failures,
        ?string $channel = null,
        ?array $reasonNeedles = null,
    ): array {
        return collect($failures)
            ->filter(function (array $failure) use ($channel, $reasonNeedles): bool {
                if ($channel !== null && ($failure['channel'] ?? '') !== $channel) {
                    return false;
                }

                if ($reasonNeedles !== null) {
                    $reason = Str::lower((string) ($failure['reason'] ?? ''));

                    return collect($reasonNeedles)->contains(
                        fn (string $needle): bool => Str::contains($reason, Str::lower($needle)),
                    );
                }

                return true;
            })
            ->filter(fn (array $failure): bool => filled($failure['customer_name'] ?? null))
            ->take(10)
            ->map(fn (array $failure): array => [
                'name' => $failure['customer_name'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $activeServices
     * @param  array<string, mixed>  $enrichmentMetadata
     */
    private function isAmcOpportunity(array $activeServices, array $enrichmentMetadata): bool
    {
        $warranty = Str::lower((string) (collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? ''));
        $amc = Str::lower((string) (collect($activeServices)->firstWhere('label', 'AMC')['status'] ?? ''));
        $metadataWarranty = Str::lower((string) ($enrichmentMetadata['warranty'] ?? ''));
        $metadataAmc = Str::lower((string) ($enrichmentMetadata['amc'] ?? ''));

        $warrantyExpired = Str::contains($warranty, 'expired') || Str::contains($metadataWarranty, 'expired');
        $amcInactive = $amc === 'not available'
            || Str::contains($amc, 'expired')
            || Str::contains($metadataAmc, 'expired')
            || $metadataAmc === ''
            || $metadataAmc === 'not available';

        return $warrantyExpired && $amcInactive;
    }

    /**
     * @param  mixed  $cached
     */
    private function isCachedInsightList(mixed $cached): bool
    {
        if ($cached === []) {
            return true;
        }

        return isset($cached[0]) && $cached[0] instanceof OperationsInsightDTO;
    }
}
