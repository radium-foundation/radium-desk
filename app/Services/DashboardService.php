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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;

class DashboardService
{
    /**
     * @return array<string, int>
     */
    public function statsFor(User $user): array
    {
        $stats = [
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

    public function recentServiceCases(string $filter = 'pending_admin', int $limit = 10): Collection
    {
        $query = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee']);

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

        return $this->sortIncidentsForDashboard($incidents)
            ->take($limit)
            ->values();
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function slaCounts(): array
    {
        $pendingIncidents = Incident::query()
            ->with('order')
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
}
