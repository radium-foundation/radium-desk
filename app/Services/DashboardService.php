<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\ApprovalNumber;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardService
{
    private const ONLINE_SESSION_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function statsFor(User $user): array
    {
        $onlineUsers = $this->onlineUsers();

        $stats = [
            'online_count' => $onlineUsers->count(),
            'online_users' => $onlineUsers,
            'total_orders' => Order::query()->count(),
            'open_incidents' => Incident::query()
                ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
                ->count(),
            'resolved_incidents' => Incident::query()
                ->where('status', IncidentStatus::Resolved)
                ->count(),
            'closed_incidents' => Incident::query()
                ->where('status', IncidentStatus::Closed)
                ->count(),
            'my_active_cases' => Incident::query()
                ->where('assigned_to_user_id', $user->id)
                ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
                ->count(),
            'waiting_for_admin' => Incident::query()
                ->where('assigned_to_user_id', $user->id)
                ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
                ->whereHas('order', function ($orderQuery): void {
                    $orderQuery->where(function ($pendingQuery): void {
                        $pendingQuery->whereNull('transaction_id')
                            ->orWhere('transaction_id', '');
                    });
                })
                ->count(),
            'high_priority_cases' => Incident::query()
                ->where('assigned_to_user_id', $user->id)
                ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
                ->where('high_priority', true)
                ->count(),
            'total_active_cases' => Incident::query()
                ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
                ->count(),
        ];

        if ($user->can('refunds.view')) {
            $stats['pending_refunds'] = RefundRequest::query()
                ->where('status', RefundStatus::Pending)
                ->count();
            $stats['approved_refunds'] = RefundRequest::query()
                ->where('status', RefundStatus::Approved)
                ->count();
            $stats['rejected_refunds'] = RefundRequest::query()
                ->where('status', RefundStatus::Rejected)
                ->count();
        }

        if ($user->can('approvals.view')) {
            $stats['pending_approvals'] = ApprovalNumber::query()
                ->where('status', ApprovalStatus::Open)
                ->count();
        }

        if ($user->hasAnyRole([RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_SUPERADMIN])) {
            $stats['approval_numbers'] = ApprovalNumber::query()->count();
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            $stats['total_users'] = User::query()->count();
            $stats['audit_log_count'] = AuditLog::query()->count();
        }

        if ($user->can('incidents.view')) {
            $stats = [
                ...$stats,
                ...$this->slaCounts(),
            ];
        }

        return $stats;
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

        return [
            'serviceCase' => $serviceCase,
            'canManageTransactions' => $canManageTransactions,
            'canSelectRows' => $canManageTransactions,
            'canReassignServiceCases' => $canManageTransactions,
            'canCreateRemarks' => $user->can('create', Remark::class),
        ];
    }

    public function recentServiceCases(string $filter = 'pending_admin', ?int $limit = 10): Collection
    {
        $query = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress]);

        match ($filter) {
            'pending_admin' => $query->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            }),
            'completed' => $query->whereHas('order', function ($orderQuery): void {
                $orderQuery->whereNotNull('transaction_id')
                    ->where('transaction_id', '!=', '');
            }),
            'high_priority' => $query->where('high_priority', true),
            'overdue' => $query->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            }),
            'warning' => $query->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            }),
            default => null,
        };

        $incidents = $query->get();

        $incidents = match ($filter) {
            'overdue' => $this->filterIncidentsBySlaStatus($incidents, ServiceCaseSlaStatus::Overdue),
            'warning' => $this->filterIncidentsBySlaStatus($incidents, ServiceCaseSlaStatus::Warning),
            default => $incidents,
        };

        $sorted = $this->sortIncidentsForDashboard($incidents);

        if ($limit !== null) {
            $sorted = $sorted->take($limit);
        }

        return $sorted->values();
    }

    public function serviceCaseLimitForFilter(string $filter): ?int
    {
        return $filter === 'pending_admin' ? null : 10;
    }

    /**
     * @return array<string, int>
     */
    public function serviceCaseFilterCounts(): array
    {
        return collect(['all', 'pending_admin', 'completed', 'high_priority'])
            ->mapWithKeys(fn (string $key): array => [$key => $this->recentServiceCases($key, null)->count()])
            ->all();
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(): array
    {
        $pendingIncidents = Incident::query()
            ->with('order')
            ->whereIn('status', [IncidentStatus::Open, IncidentStatus::InProgress])
            ->whereHas('order', function ($orderQuery): void {
                $orderQuery->where(function ($pendingQuery): void {
                    $pendingQuery->whereNull('transaction_id')
                        ->orWhere('transaction_id', '');
                });
            })
            ->get();

        $now = now();

        return [
            'overdue_cases' => $pendingIncidents
                ->filter(fn (Incident $incident): bool => $incident->slaStatus($now) === ServiceCaseSlaStatus::Overdue)
                ->count(),
            'warning_cases' => $pendingIncidents
                ->filter(fn (Incident $incident): bool => $incident->slaStatus($now) === ServiceCaseSlaStatus::Warning)
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function sortIncidentsForDashboard(Collection $incidents): Collection
    {
        $now = now();

        return $incidents
            ->sort(function (Incident $left, Incident $right) use ($now): int {
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
    public function renderKpiStrip(array $stats): string
    {
        return view('dashboard.partials.kpi-strip', compact('stats'))->render();
    }
}
