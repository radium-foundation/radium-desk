<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;

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

        $configured = $this->resolveConfiguredAssignee();

        if ($configured !== null) {
            return $configured;
        }

        return $this->resolveCapabilityAssignee();
    }

    public function isDesignatedAssignee(Incident $incident, User $user): bool
    {
        if (! $this->matches($incident)) {
            return false;
        }

        if (! $this->canOperateHardwareFulfilment($user)) {
            return false;
        }

        $resolved = $this->resolveAssignee($incident);

        return $resolved !== null && $resolved->is($user);
    }

    public function canOperateHardwareFulfilment(User $user): bool
    {
        if ($user->trashed() || ! $user->is_active) {
            return false;
        }

        if (! HardwareFulfilmentAccess::allows($user)) {
            return false;
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return false;
        }

        return true;
    }

    private function resolveConfiguredAssignee(): ?User
    {
        $email = strtolower(trim((string) config(
            'service_case_assignment.hardware_order.assignee_email',
            '',
        )));

        if ($email === '') {
            return null;
        }

        $assignee = User::query()->where('email', $email)->first();

        if ($assignee === null) {
            Log::notice('Configured hardware order assignee email does not match an active user.', [
                'assignee_email' => $email,
            ]);

            return null;
        }

        if (! $this->canOperateHardwareFulfilment($assignee)) {
            Log::notice('Configured hardware order assignee lacks hardware fulfilment operate permission.', [
                'assignee_email' => $email,
                'user_id' => $assignee->id,
            ]);

            return null;
        }

        return $assignee;
    }

    private function resolveCapabilityAssignee(): ?User
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->canOperateHardwareFulfilment($user))
            ->values();

        if ($candidates->isEmpty()) {
            Log::warning('No eligible hardware fulfilment operator is available for hardware order routing.');

            return null;
        }

        return $candidates->first();
    }
}
