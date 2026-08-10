<?php

namespace App\Services;

use App\Data\RecentActivityStreams;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\TodoStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Todo;
use App\Models\User;
use App\ReadModels\Cases\CaseQueueReadModel;
use App\Services\Dashboard\AgentNextAppointmentResolver;
use App\Services\Dashboard\DashboardKpiAggregator;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Dashboard\LiveReverbMetricsBatch;
use App\Services\Dashboard\OperatorDashboardCache;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\Operations\OperationsRoleService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Support\Dashboard\DashboardIncidentSortComparator;
use App\Support\Dashboard\RecentActivityPresenter;
use App\Support\Dashboard\ScheduledAppointmentRowBadgePresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardService
{
    private const ONLINE_SESSION_MINUTES = 5;

    private ?DashboardSnapshot $snapshot = null;

    public function __construct(
        private readonly DashboardKpiAggregator $kpiAggregator,
        private readonly DashboardIncidentSortComparator $incidentSortComparator,
        private readonly RecentActivityPresenter $recentActivityPresenter,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

    private function caseQueue(): CaseQueueReadModel
    {
        return app(CaseQueueReadModel::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function statsFor(User $user): array
    {
        return [
            ...$this->fastChangingStatsFor($user),
            ...$this->slowChangingStatsFor($user),
        ];
    }

    /**
     * Fast-changing operator metrics (cases, presence, role KPIs).
     *
     * @return array<string, mixed>
     */
    public function fastChangingStatsFor(User $user): array
    {
        return $this->buildFastChangingStats($user, leanForKpiStrip: false);
    }

    /**
     * Stats required to render `dashboard.partials.kpi-strip` (and agent cards).
     * Skips aggregates that are not displayed there (still available via statsFor()).
     *
     * @return array<string, mixed>
     */
    public function fastChangingStatsForKpiStrip(User $user): array
    {
        return $this->buildFastChangingStats($user, leanForKpiStrip: true);
    }

    /**
     * Indexed COUNT of operationally active incidents — no snapshot or queue classification.
     */
    public function operationallyActiveCasesCount(): int
    {
        return $this->kpiAggregator->operationallyActiveCasesCount();
    }

    /**
     * Indexed COUNT of pending refund requests — no snapshot or queue classification.
     */
    public function pendingRefundsCount(): int
    {
        return $this->kpiAggregator->refundStatusCounts()['pending'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFastChangingStats(User $user, bool $leanForKpiStrip): array
    {
        $onlineUsers = $this->onlineUsers();
        $snapshot = $this->snapshot();
        $activeIncidents = $snapshot->activeIncidents();
        $activeKpis = $this->kpiAggregator->activeIncidentKpis($activeIncidents, $user);
        $operationalKpis = $this->caseQueue()->operationalKpiCounts(
            $this->resolveKpiScopeUser($user),
            $snapshot,
        );
        $roles = app(OperationsRoleService::class);

        $stats = [
            'online_count' => $onlineUsers->count(),
            'online_users' => $onlineUsers,
            'open_cases' => $operationalKpis['open_cases'],
            'waiting_cases' => $operationalKpis['waiting_cases'],
            'open_incidents' => $operationalKpis['open_cases'],
            'my_active_cases' => $activeKpis['my_active_cases'],
            'waiting_for_admin' => $activeKpis['waiting_for_admin'],
            'high_priority_cases' => $activeKpis['high_priority_cases'],
            'total_active_cases' => $this->kpiAggregator->operationallyActiveCasesCount(),
        ];

        if (! $leanForKpiStrip) {
            $incidentStatusCounts = $this->kpiAggregator->incidentStatusCounts();
            $stats['resolved_incidents'] = $incidentStatusCounts['resolved'];
            $stats['closed_incidents'] = $incidentStatusCounts['closed'];
        }

        $stats['email_intake_widget'] = app(IncomingEmailIntakeCounterService::class)
            ->dashboardWidget($user);

        if ($roles->usesSupportQueues($user)) {
            $stats = [
                ...$stats,
                ...$this->kpiAggregator->supportAgentKpis($snapshot, $user),
            ];

            $nextAppointment = app(AgentNextAppointmentResolver::class)->resolve($snapshot, $user);

            $stats['next_appointment'] = $nextAppointment?->toArray();
        }

        if ($user->can('refunds.view')) {
            $refundCounts = $this->kpiAggregator->refundStatusCounts();

            $stats['pending_refunds'] = $refundCounts['pending'];

            if (! $leanForKpiStrip) {
                $stats['approved_refunds'] = $refundCounts['approved'];
                $stats['rejected_refunds'] = $refundCounts['rejected'];
            }
        }

        if (! $leanForKpiStrip) {
            if ($user->can('approvals.view')) {
                $stats['pending_approvals'] = $this->kpiAggregator->approvalCounts()['open'];
            }

            if ($user->hasAnyRole([RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_SUPERADMIN])) {
                $stats['approval_numbers'] = $this->kpiAggregator->approvalCounts()['total'];
                $stats['automation_health'] = app(ServiceCaseAutomationHealthService::class)
                    ->countsFor($activeIncidents);
            }
        }

        if ($user->can('incidents.view')) {
            // KPI strip only renders overdue_cases; service/hardware splits remain for full statsFor().
            $slaCounts = $this->caseQueue()->slaCounts(snapshot: $snapshot);
            $stats = [
                ...$stats,
                ...$slaCounts,
            ];

            if (! $leanForKpiStrip) {
                $serviceSla = $this->caseQueue()->serviceSlaCounts(snapshot: $snapshot);
                $hardwareSla = $this->caseQueue()->hardwareSlaCounts(snapshot: $snapshot);

                $stats['service_overdue_cases'] = $serviceSla['overdue_cases'];
                $stats['service_warning_cases'] = $serviceSla['warning_cases'];
                $stats['hardware_overdue_cases'] = $hardwareSla['overdue_cases'];
                $stats['hardware_warning_cases'] = $hardwareSla['warning_cases'];
            }
        }

        if ($user->can('viewAny', Todo::class)) {
            $stats['todo_widget'] = $this->todoWidgetCounts($user);
        }

        return $stats;
    }

    /**
     * @return array{pending: int, overdue: int}
     */
    private function todoWidgetCounts(User $user): array
    {
        $base = Todo::query()
            ->when(! $user->can('todos.manage'), function ($query) use ($user): void {
                $query->where(function ($scoped) use ($user): void {
                    $scoped->where('created_by', $user->id)
                        ->orWhere('assigned_to', $user->id);
                });
            })
            ->where('status', TodoStatus::Open);

        return [
            'pending' => (clone $base)->count(),
            'overdue' => (clone $base)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
        ];
    }

    /**
     * Slow-changing admin scalars (full-table COUNTs), served from short TTL cache.
     *
     * @return array<string, mixed>
     */
    public function slowChangingStatsFor(User $user): array
    {
        $scalars = app(OperatorDashboardCache::class)->slowScalars();

        $stats = [
            'total_orders' => $scalars['total_orders'],
        ];

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            $stats['total_users'] = $scalars['total_users'];
            $stats['audit_log_count'] = $scalars['audit_log_count'];
        }

        return $stats;
    }

    private function resolveKpiScopeUser(User $user): ?User
    {
        return null;
    }

    /**
     * @return Collection<int, User>
     */
    public function onlineUsers(): Collection
    {
        $threshold = now()->subMinutes(self::ONLINE_SESSION_MINUTES)->getTimestamp();

        return User::query()
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.name'])
            ->where('users.is_active', true)
            ->whereIn('users.id', function ($query) use ($threshold): void {
                $query->select('user_id')
                    ->from('sessions')
                    ->where('last_activity', '>=', $threshold)
                    ->whereNotNull('user_id');
            })
            ->orderBy('users.first_name')
            ->orderBy('users.last_name')
            ->get();
    }

    public function onlineUserDisplayName(User $user): string
    {
        $firstName = $user->firstName();
        $lastName = $user->lastName();

        if ($lastName === '') {
            return $firstName;
        }

        return trim($firstName.' '.Str::substr($lastName, 0, 1));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return list<array{id: int, name: string}>
     */
    public function onlineUsersPayload(array $stats): array
    {
        /** @var Collection<int, User> $onlineUsers */
        $onlineUsers = $stats['online_users'] ?? collect();

        return $onlineUsers
            ->sortBy(
                fn (User $user): string => Str::lower($this->onlineUserDisplayName($user)),
                SORT_NATURAL,
            )
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $this->onlineUserDisplayName($user),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceCaseRowViewData(Incident $serviceCase, User $user, ?string $dashboardOperationQueue = null): array
    {
        $canManageTransactions = $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $order = $serviceCase->order;
        $verificationService = app(CustomerVerificationService::class);
        $commercialState = null;
        $commercialResolver = app(\App\Services\Commercial\CommercialStateResolver::class);

        if ($commercialResolver->enabled()) {
            $commercialState = $commercialResolver->forIncident($serviceCase)->toArray();
        }

        return [
            'serviceCase' => $serviceCase,
            'compactAgentLayout' => app(OperationsRoleService::class)->usesSupportQueues($user),
            'canManageTransactions' => $canManageTransactions,
            'canSelectRows' => $canManageTransactions,
            'requiresLegacyVerification' => $order !== null && $verificationService->requiresLegacyVerification($order),
            'legacyVerificationUrl' => $order !== null
                ? route('orders.legacy-verification.store', $order)
                : null,
            'legacyVerificationMode' => $order !== null
                ? $verificationService->legacyVerificationMode($order)
                : 'customer',
            'isScheduledWorkspace' => $dashboardOperationQueue === DashboardPersonalizationService::QUEUE_SCHEDULED,
            'commercialState' => $commercialState,
        ];
    }

    /**
     * @param  list<int>  $incidentIds
     * @return Collection<int, array{incident_id: int, html: string}>
     */
    public function serviceCaseRowsForSearch(array $incidentIds, User $user): Collection
    {
        if ($incidentIds === [] || ! $user->can('incidents.view')) {
            return collect();
        }

        $incidents = Incident::query()
            ->with(['order.transactionAssigner', 'order.legacyImporter', 'order.refundRequests', 'refundRequests', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        return collect($incidentIds)
            ->map(fn (int $incidentId): ?Incident => $incidents->get($incidentId))
            ->filter(fn (?Incident $incident): bool => $incident instanceof Incident && $user->can('view', $incident))
            ->map(fn (Incident $incident): array => [
                'incident_id' => $incident->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->serviceCaseRowViewData($incident, $user),
                )->render(),
            ])
            ->values();
    }

    public function serviceCasePageSize(): int
    {
        return max(1, (int) config('dashboard.service_cases_page_size', 35));
    }

    public function serviceCaseLoadMoreSize(): int
    {
        return max(1, (int) config('dashboard.service_cases_load_more_size', 25));
    }

    public function serviceCaseLimitForFilter(string $filter): int
    {
        return $this->serviceCasePageSize();
    }

    public function recentServiceCases(
        string $filter = 'pending_admin',
        ?int $limit = null,
        ?User $assignedTo = null,
        bool $prioritizeRecentAssignments = false,
        int $offset = 0,
        ?string $searchQuery = null,
    ): Collection {
        $limit ??= $this->serviceCasePageSize();

        $sorted = $this->sortedIncidentsForFilter(
            $filter,
            $assignedTo,
            $prioritizeRecentAssignments,
            $searchQuery,
        );

        if ($offset > 0) {
            $sorted = $sorted->slice($offset, $limit);
        } else {
            $sorted = $sorted->take($limit);
        }

        return $this->hydrateIncidentsForRowRendering($sorted->values());
    }

    public function serviceCaseSearchText(Incident $incident): string
    {
        $order = $incident->order;
        $parts = array_filter([
            $order?->order_id,
            $incident->display_reference,
            $order?->customer_name,
            $order?->customer_email,
            $order?->customer_phone,
            $order?->serial_number,
            $order?->displayDeviceModelName(),
        ], fn ($value): bool => filled($value));

        return strtolower(implode(' ', $parts));
    }

    public function incidentMatchesQuickSearch(Incident $incident, string $query): bool
    {
        $query = trim($query);

        if ($query === '') {
            return true;
        }

        $tokens = preg_split('/\s+/u', strtolower($query));

        if ($tokens === false) {
            return true;
        }

        $tokens = array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));

        if ($tokens === []) {
            return true;
        }

        $searchText = $this->serviceCaseSearchText($incident);

        foreach ($tokens as $token) {
            if (! str_contains($searchText, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    public function filterIncidentsByQuickSearch(Collection $incidents, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return $incidents->values();
        }

        return $incidents
            ->filter(fn (Incident $incident): bool => $this->incidentMatchesQuickSearch($incident, $query))
            ->values();
    }

    public function matchingServiceCaseCount(
        string $filter,
        ?User $assignedTo,
        bool $prioritizeRecentAssignments,
        string $searchQuery,
    ): int {
        return $this->sortedIncidentsForFilter(
            $filter,
            $assignedTo,
            $prioritizeRecentAssignments,
            $searchQuery,
        )->count();
    }

    /**
     * @return Collection<int, Incident>
     */
    private function sortedIncidentsForFilter(
        string $filter,
        ?User $assignedTo,
        bool $prioritizeRecentAssignments,
        ?string $searchQuery = null,
    ): Collection {
        $snapshot = $this->snapshot();
        $incidents = OperationQueue::tryFrom($filter) !== null
            ? $snapshot->incidentsForQueue($filter, $assignedTo)
            : $snapshot->incidentsForFilter($filter, $assignedTo);

        $incidents = match ($filter) {
            'overdue' => $this->filterIncidentsBySlaStatus($incidents, ServiceCaseSlaStatus::Overdue),
            'warning' => $this->filterIncidentsBySlaStatus($incidents, ServiceCaseSlaStatus::Warning),
            default => $incidents,
        };

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $incidents = $this->filterIncidentsByQuickSearch($incidents, $searchQuery);
        }

        return $this->sortIncidentsForDashboard($incidents, $prioritizeRecentAssignments, $filter);
    }

    /**
     * @param  Collection<int, Incident>  $cases
     * @return list<array{incident_id: int, html: string}>
     */
    public function mapServiceCaseRows(Collection $cases, User $user, ?string $dashboardOperationQueue = null): array
    {
        if ($cases->isEmpty()) {
            return [];
        }

        if (! $this->incidentsAreRowRenderable($cases)) {
            $cases = $this->hydrateIncidentsForRowRendering($cases);
        }

        $orders = $cases
            ->map(fn (Incident $incident): mixed => $incident->order)
            ->filter(fn (mixed $order): bool => $order instanceof Order);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->warmFromOrders($orders);

        return $cases
            ->map(fn (Incident $serviceCase): array => [
                'incident_id' => $serviceCase->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->serviceCaseRowViewData($serviceCase, $user, $dashboardOperationQueue),
                )->render(),
            ])
            ->values()
            ->all();
    }

    /**
     * Reload full row-renderable incidents — never use lean classification models for Blade.
     *
     * @param  Collection<int, Incident>  $cases
     * @return Collection<int, Incident>
     */
    public function hydrateIncidentsForRowRendering(Collection $cases): Collection
    {
        if ($cases->isEmpty()) {
            return $cases;
        }

        $rowIncidents = Incident::query()
            ->whereIn('id', $cases->pluck('id'))
            ->with([
                'order.deviceModel',
                'order.transactionAssigner',
                'order.legacyImporter',
                'order.refundRequests.requester',
                'order.refundRequests.reviewer',
                'order.refundRequests.executor',
                'refundRequests.requester',
                'refundRequests.reviewer',
                'refundRequests.executor',
                'closeOutcomes.closer',
                'creator',
                'assignee.roles',
                'activeWaitingState',
                'activeBusinessHold',
                'supportAppointments',
            ])
            ->get()
            ->keyBy('id');

        return $cases->map(
            fn (Incident $incident): Incident => $rowIncidents->get($incident->id) ?? $incident,
        );
    }

    /**
     * @param  Collection<int, Incident>  $cases
     */
    private function incidentsAreRowRenderable(Collection $cases): bool
    {
        $first = $cases->first();

        return $first instanceof Incident && array_key_exists('source', $first->getAttributes());
    }

    /**
     * @return array{
     *     rows: list<array{incident_id: int, html: string}>,
     *     incident_ids: Collection<int, int>,
     *     service_cases_empty: bool,
     *     service_cases_empty_html: string,
     *     total_count: int,
     *     has_more: bool,
     *     loaded_count: int,
     * }
     */
    /**
     * Authoritative queue membership for heartbeat reconcile — sorted incident IDs only.
     * Reuses the request-scoped snapshot and sorting path; never hydrates rows or renders Blade.
     *
     * @return array{
     *     incident_ids: Collection<int, int>,
     *     total_count: int,
     *     has_more: bool,
     *     loaded_count: int,
     *     service_cases_empty: bool,
     * }
     */
    public function serviceCaseMembershipPayload(
        string $filter,
        ?User $assignedTo,
        bool $prioritizeRecentAssignments,
        ?int $windowLimit = null,
    ): array {
        $pageSize = $this->serviceCasePageSize();
        $windowLimit = min($windowLimit ?? $pageSize, $pageSize);

        $sorted = $this->sortedIncidentsForFilter(
            $filter,
            $assignedTo,
            $prioritizeRecentAssignments,
        );

        $totalCount = $sorted->count();
        $window = $sorted->take($windowLimit);
        $loadedCount = $window->count();

        return [
            'incident_ids' => $window->pluck('id')->values(),
            'total_count' => $totalCount,
            'has_more' => $loadedCount < $totalCount,
            'loaded_count' => $loadedCount,
            'service_cases_empty' => $totalCount === 0,
        ];
    }

    public function serviceCasesPayload(
        User $user,
        string $filter,
        ?User $assignedTo,
        bool $prioritizeRecentAssignments,
        int $limit,
        int $offset = 0,
        ?array $filterCounts = null,
        ?string $searchQuery = null,
        ?string $dashboardOperationQueue = null,
    ): array {
        $normalizedSearchQuery = $searchQuery !== null ? trim($searchQuery) : null;
        $hasSearchQuery = $normalizedSearchQuery !== null && $normalizedSearchQuery !== '';

        $cases = $this->recentServiceCases(
            $filter,
            $limit,
            $assignedTo,
            $prioritizeRecentAssignments,
            $offset,
            $hasSearchQuery ? $normalizedSearchQuery : null,
        );

        if ($hasSearchQuery) {
            $totalCount = $this->matchingServiceCaseCount(
                $filter,
                $assignedTo,
                $prioritizeRecentAssignments,
                $normalizedSearchQuery,
            );
        } else {
            $filterCounts ??= $this->serviceCaseFilterCounts($assignedTo, $user);
            $totalCount = $filterCounts[$filter] ?? $cases->count();
        }

        $loadedCount = $offset + $cases->count();

        return [
            'rows' => $this->mapServiceCaseRows($cases, $user, $dashboardOperationQueue),
            'incident_ids' => $cases->pluck('id')->values(),
            'service_cases_empty' => $cases->isEmpty(),
            'service_cases_empty_html' => view('dashboard.partials.service-cases-empty')->render(),
            'total_count' => $totalCount,
            'has_more' => $loadedCount < $totalCount,
            'loaded_count' => $loadedCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function serviceCaseFilterCounts(?User $assignedTo = null, ?User $user = null): array
    {
        return $this->caseQueue()->filterCounts($assignedTo, $user, $this->snapshot());
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(): array
    {
        return $this->caseQueue()->slaCounts(snapshot: $this->snapshot());
    }

    public function snapshot(): DashboardSnapshot
    {
        return $this->snapshot ??= DashboardSnapshot::load();
    }

    public function forgetSnapshot(): void
    {
        $this->snapshot = null;
        app(DashboardSnapshotStore::class)->forget();
    }

    /**
     * @return array{
     *     kpi_strip_html: string,
     *     service_case_filter_counts: array<string, int>,
     *     next_appointment: array<string, mixed>|null,
     *     online_count: int,
     *     online_users: list<array{id: int, name: string}>,
     *     fast: array{
     *         service_case_filter_counts: array<string, int>,
     *         next_appointment: array<string, mixed>|null,
     *         online_count: int,
     *         online_users: list<array{id: int, name: string}>,
     *     },
     *     slow: array<string, mixed>,
     * }
     */
    public function liveMetricsFor(
        User $user,
        ?string $requestedQueue = null,
        ?string $legacyView = null,
        ?string $legacyFilter = null,
        ?User $assignedToForFilterCounts = null,
    ): array {
        if ($assignedToForFilterCounts !== null) {
            $assignedTo = $assignedToForFilterCounts;
        } elseif ($requestedQueue !== null || $legacyView !== null || $legacyFilter !== null) {
            $context = $this->dashboardPersonalization->resolveLiveDashboardContext(
                $user,
                $requestedQueue,
                $legacyView,
                $legacyFilter,
            );
            $assignedTo = $context['assigned_to'];
        } else {
            $assignedTo = null;
        }

        $fast = $this->fastChangingStatsForKpiStrip($user);
        $stats = $fast;

        $filterCounts = $user->can('incidents.view')
            ? $this->serviceCaseFilterCounts($assignedTo, $user)
            : [];
        $onlineUsers = $this->onlineUsersPayload($stats);

        return [
            'kpi_strip_html' => $this->renderKpiStrip($stats, $user),
            'service_case_filter_counts' => $filterCounts,
            'next_appointment' => $fast['next_appointment'] ?? null,
            'online_count' => $fast['online_count'],
            'online_users' => $onlineUsers,
            'fast' => [
                'service_case_filter_counts' => $filterCounts,
                'next_appointment' => $fast['next_appointment'] ?? null,
                'online_count' => $fast['online_count'],
                'online_users' => $onlineUsers,
            ],
            'slow' => [],
        ];
    }

    /**
     * @return array{kpi_strip_html: string, service_case_filter_count_variants: array<string, array<string, int>>}
     */
    public function liveReverbMetricsFor(User $user, ?LiveReverbMetricsBatch $batch = null): array
    {
        $stats = $this->fastChangingStatsForKpiStrip($user);
        $variants = [
            DashboardPersonalizationService::SCOPE_OPERATIONS => $user->can('incidents.view')
                ? ($batch?->operationsFilterCounts ?? $this->serviceCaseFilterCounts(null, $user))
                : [],
        ];

        if ($this->dashboardPersonalization->usesSupportScopeVariants($user)) {
            $variants[DashboardPersonalizationService::SCOPE_SUPPORT] = $user->can('incidents.view')
                ? ($batch?->supportFilterCountsByUserId[$user->id] ?? $this->serviceCaseFilterCounts($user, $user))
                : [];
        }

        return [
            'kpi_strip_html' => $this->renderKpiStrip($stats, $user),
            'service_case_filter_count_variants' => $variants,
        ];
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    public function prepareLiveReverbMetricsBatch(Collection $recipients): LiveReverbMetricsBatch
    {
        $this->snapshot();

        $operationsFilterCounts = $this->serviceCaseFilterCounts(null, null);

        $supportFilterCountsByUserId = [];
        foreach ($recipients as $recipient) {
            if ($this->dashboardPersonalization->usesSupportScopeVariants($recipient)) {
                $supportFilterCountsByUserId[$recipient->id] = $this->serviceCaseFilterCounts($recipient, $recipient);
            }
        }

        return new LiveReverbMetricsBatch(
            operationsFilterCounts: $operationsFilterCounts,
            supportFilterCountsByUserId: $supportFilterCountsByUserId,
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function sortIncidentsForDashboard(
        Collection $incidents,
        bool $prioritizeRecentAssignments = false,
        ?string $filter = null,
    ): Collection {
        if (DashboardIncidentSortComparator::queueUsesAppointmentSort($filter)) {
            return app(ScheduledAppointmentRowBadgePresenter::class)->sortIncidents($incidents);
        }

        return $this->incidentSortComparator->sort(
            $incidents,
            $prioritizeRecentAssignments,
            $filter,
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function filterIncidentsBySlaStatus(Collection $incidents, ServiceCaseSlaStatus $status): Collection
    {
        $now = now();

        return $incidents
            ->filter(fn (Incident $incident): bool => $incident->isPendingAdmin() && $incident->slaStatus($now) === $status)
            ->values();
    }

    public function recentActivityStreams(User $viewer, ?int $perStreamLimit = null): RecentActivityStreams
    {
        $fetchLimit = (int) config('dashboard-activity.limits.fetch', 60);

        $logs = AuditLog::query()
            ->with(RecentActivityPresenter::eagerLoadRelations())
            ->latest('created_at')
            ->limit($fetchLimit)
            ->get();

        return $this->recentActivityPresenter->presentStreams(
            $logs,
            $viewer,
            $perStreamLimit,
        );
    }

    /**
     * @param  array<string, int>  $stats
     */
    public function renderKpiStrip(array $stats, ?User $viewer = null): string
    {
        $viewer ??= auth()->user();

        return view('dashboard.partials.kpi-strip', compact('stats', 'viewer'))->render();
    }
}
