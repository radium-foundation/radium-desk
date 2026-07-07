<?php

namespace App\Services;

use App\Enums\OperationQueue;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\Dashboard\DashboardKpiAggregator;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Operations\OperationsRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardService
{
    private const ONLINE_SESSION_MINUTES = 5;

    private ?DashboardSnapshot $snapshot = null;

    public function __construct(
        private readonly DashboardKpiAggregator $kpiAggregator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statsFor(User $user): array
    {
        $onlineUsers = $this->onlineUsers();
        $snapshot = $this->snapshot();
        $activeIncidents = $snapshot->activeIncidents();
        $activeKpis = $this->kpiAggregator->activeIncidentKpis($activeIncidents, $user);
        $incidentStatusCounts = $this->kpiAggregator->incidentStatusCounts();
        $operationalKpis = $snapshot->operationalKpiCounts($this->resolveKpiScopeUser($user));
        $roles = app(OperationsRoleService::class);

        $stats = [
            'online_count' => $onlineUsers->count(),
            'online_users' => $onlineUsers,
            'total_orders' => Order::query()->count(),
            'open_cases' => $operationalKpis['open_cases'],
            'waiting_cases' => $operationalKpis['waiting_cases'],
            'open_incidents' => $operationalKpis['open_cases'],
            'resolved_incidents' => $incidentStatusCounts['resolved'],
            'closed_incidents' => $incidentStatusCounts['closed'],
            'my_active_cases' => $activeKpis['my_active_cases'],
            'waiting_for_admin' => $activeKpis['waiting_for_admin'],
            'high_priority_cases' => $activeKpis['high_priority_cases'],
            'total_active_cases' => $activeKpis['total_active_cases'],
        ];

        if ($roles->usesSupportQueues($user)) {
            $stats = [
                ...$stats,
                ...$this->kpiAggregator->supportAgentKpis($snapshot, $user),
            ];
        }

        if ($user->can('refunds.view')) {
            $refundCounts = $this->kpiAggregator->refundStatusCounts();

            $stats['pending_refunds'] = $refundCounts['pending'];
            $stats['approved_refunds'] = $refundCounts['approved'];
            $stats['rejected_refunds'] = $refundCounts['rejected'];
        }

        if ($user->can('approvals.view')) {
            $stats['pending_approvals'] = $this->kpiAggregator->approvalCounts()['open'];
        }

        if ($user->hasAnyRole([RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_SUPERADMIN])) {
            $stats['approval_numbers'] = $this->kpiAggregator->approvalCounts()['total'];
            $stats['automation_health'] = app(ServiceCaseAutomationHealthService::class)
                ->countsFor($activeIncidents);
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            $stats['total_users'] = User::query()->count();
            $stats['audit_log_count'] = AuditLog::query()->count();
        }

        if ($user->can('incidents.view')) {
            $slaCounts = $snapshot->slaCounts();
            $serviceSla = $snapshot->serviceSlaCounts();
            $hardwareSla = $snapshot->hardwareSlaCounts();

            $stats = [
                ...$stats,
                ...$slaCounts,
                'service_overdue_cases' => $serviceSla['overdue_cases'],
                'service_warning_cases' => $serviceSla['warning_cases'],
                'hardware_overdue_cases' => $hardwareSla['overdue_cases'],
                'hardware_warning_cases' => $hardwareSla['warning_cases'],
            ];
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
    public function serviceCaseRowViewData(Incident $serviceCase, User $user): array
    {
        $canManageTransactions = $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $order = $serviceCase->order;
        $verificationService = app(CustomerVerificationService::class);

        return [
            'serviceCase' => $serviceCase,
            'canManageTransactions' => $canManageTransactions,
            'canSelectRows' => $canManageTransactions,
            'canReassignServiceCases' => $canManageTransactions,
            'canCreateRemarks' => $user->can('create', Remark::class),
            'requiresLegacyVerification' => $order !== null && $verificationService->requiresLegacyVerification($order),
            'legacyVerificationUrl' => $order !== null
                ? route('orders.legacy-verification.store', $order)
                : null,
            'legacyVerificationMode' => $order !== null
                ? $verificationService->legacyVerificationMode($order)
                : 'customer',
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
            ->with(['order.transactionAssigner', 'order.legacyImporter', 'creator', 'assignee'])
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

        return $sorted->values();
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

        return $this->sortIncidentsForDashboard($incidents, $prioritizeRecentAssignments);
    }

    /**
     * @param  Collection<int, Incident>  $cases
     * @return list<array{incident_id: int, html: string}>
     */
    public function mapServiceCaseRows(Collection $cases, User $user): array
    {
        return $cases
            ->map(fn (Incident $serviceCase): array => [
                'incident_id' => $serviceCase->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->serviceCaseRowViewData($serviceCase, $user),
                )->render(),
            ])
            ->values()
            ->all();
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
    public function serviceCasesPayload(
        User $user,
        string $filter,
        ?User $assignedTo,
        bool $prioritizeRecentAssignments,
        int $limit,
        int $offset = 0,
        ?array $filterCounts = null,
        ?string $searchQuery = null,
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
            'rows' => $this->mapServiceCaseRows($cases, $user),
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
        return $this->snapshot()->filterCounts($assignedTo, $user);
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(): array
    {
        return $this->snapshot()->slaCounts();
    }

    public function snapshot(): DashboardSnapshot
    {
        return $this->snapshot ??= DashboardSnapshot::load();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function sortIncidentsForDashboard(Collection $incidents, bool $prioritizeRecentAssignments = false): Collection
    {
        $now = now();

        return $incidents
            ->sort(function (Incident $left, Incident $right) use ($now, $prioritizeRecentAssignments): int {
                if ($prioritizeRecentAssignments) {
                    $updatedComparison = ($right->updated_at?->timestamp ?? 0) <=> ($left->updated_at?->timestamp ?? 0);

                    if ($updatedComparison !== 0) {
                        return $updatedComparison;
                    }
                }

                $rankComparison = $left->slaSortRank($now) <=> $right->slaSortRank($now);

                if ($rankComparison !== 0) {
                    return $rankComparison;
                }

                return ($left->created_at?->timestamp ?? 0) <=> ($right->created_at?->timestamp ?? 0);
            })
            ->values();
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

    public function recentActivity(int $limit = 10): Collection
    {
        return AuditLog::query()
            ->with('user')
            ->latest('created_at')
            ->limit($limit)
            ->get();
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
