<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class ServiceCaseOrderAssignmentRoutingService
{
    public function matches(Incident $incident): bool
    {
        $incident->loadMissing('order');

        return Order::isHardwareOrderId($incident->order?->order_id);
    }

    public function resolveAssignee(Incident $incident): ?User
    {
        if (! $this->matches($incident)) {
            return null;
        }

        $email = strtolower(trim((string) config(
            'service_case_assignment.hardware_order.assignee_email',
            'sumit@radiumbox.com',
        )));

        if ($email === '') {
            return null;
        }

        $assignee = User::query()->where('email', $email)->first();

        if ($assignee === null || $assignee->trashed() || ! $assignee->is_active) {
            return null;
        }

        if (! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ])) {
            return null;
        }

        return $assignee;
    }

    public function isDesignatedAssignee(Incident $incident, User $user): bool
    {
        $assignee = $this->resolveAssignee($incident);

        return $assignee !== null && $assignee->is($user);
    }
}
