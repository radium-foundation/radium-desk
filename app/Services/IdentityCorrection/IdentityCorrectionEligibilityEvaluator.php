<?php

namespace App\Services\IdentityCorrection;

use App\Data\Eligibility\EligibilityResult;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class IdentityCorrectionEligibilityEvaluator
{
    public const KIND_CUSTOMER = 'customer';

    public const KIND_SERIAL = 'serial';

    public const KIND_DEVICE_MODEL = 'device_model';

    public function evaluateBase(Incident $incident, User $user, string $kind): EligibilityResult
    {
        if (! $user->can('update', $incident)) {
            return EligibilityResult::deny('You do not have permission to update this service case.');
        }

        $incident->loadMissing('order');

        if ($incident->order === null) {
            return EligibilityResult::deny('This service case is not linked to an order.');
        }

        if (! $this->allowsActiveWorkflowState($incident, $user)) {
            return EligibilityResult::deny($this->inactiveWorkflowReason($incident, $kind));
        }

        if (! $this->allowsIdentityCorrection($user, $incident->order, $kind)) {
            return EligibilityResult::deny($this->identityCorrectionDenialReason($user, $incident->order, $kind));
        }

        return EligibilityResult::allow();
    }

    private function allowsIdentityCorrection(User $user, Order $order, string $kind): bool
    {
        if ($kind === self::KIND_CUSTOMER) {
            return $user->can('correctIdentity', $order);
        }

        return $user->can('correctOrderIdentity', $order);
    }

    private function identityCorrectionDenialReason(User $user, Order $order, string $kind): string
    {
        if ($kind === self::KIND_CUSTOMER) {
            return 'Admin permission required.';
        }

        if (! $user->can(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY)
            && ! $user->can('correctIdentity', $order)) {
            return 'Correct Order Identity permission required.';
        }

        if (! $order->isCashfreeVerified()) {
            return 'Identity correction is only available for paid orders.';
        }

        if ($order->isInquiryOrder()) {
            return 'Identity correction is not available for inquiry orders.';
        }

        if ($order->isProductOrder()) {
            return 'Identity correction is not available for hardware orders.';
        }

        return 'Identity correction is not available for this order.';
    }

    private function allowsActiveWorkflowState(Incident $incident, User $user): bool
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return true;
        }

        return $incident->isActive();
    }

    private function inactiveWorkflowReason(Incident $incident, string $kind): string
    {
        if ($incident->status === IncidentStatus::Closed) {
            return match ($kind) {
                self::KIND_SERIAL => 'Closed cases cannot modify serial numbers.',
                self::KIND_DEVICE_MODEL => 'Closed cases cannot modify device models.',
                default => 'Closed cases cannot modify customer identity.',
            };
        }

        if ($incident->status === IncidentStatus::Resolved) {
            return match ($kind) {
                self::KIND_SERIAL => 'Resolved cases cannot modify serial numbers.',
                self::KIND_DEVICE_MODEL => 'Resolved cases cannot modify device models.',
                default => 'Resolved cases cannot modify customer identity.',
            };
        }

        return 'This service case is not in an active workflow state.';
    }
}
