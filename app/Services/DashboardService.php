<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
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

        return $stats;
    }

    public function recentServiceCases(int $limit = 10): Collection
    {
        return Incident::query()
            ->with(['order', 'creator'])
            ->latest()
            ->limit($limit)
            ->get();
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
