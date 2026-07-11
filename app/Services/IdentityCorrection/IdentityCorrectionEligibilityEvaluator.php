<?php

namespace App\Services\IdentityCorrection;

use App\Data\Eligibility\EligibilityResult;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class IdentityCorrectionEligibilityEvaluator
{
    public const KIND_CUSTOMER = 'customer';

    public const KIND_SERIAL = 'serial';

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

        if (! $user->can('correctIdentity', $incident->order)) {
            return EligibilityResult::deny('Admin permission required.');
        }

        return EligibilityResult::allow();
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
            return $kind === self::KIND_SERIAL
                ? 'Closed cases cannot modify serial numbers.'
                : 'Closed cases cannot modify customer identity.';
        }

        if ($incident->status === IncidentStatus::Resolved) {
            return $kind === self::KIND_SERIAL
                ? 'Resolved cases cannot modify serial numbers.'
                : 'Resolved cases cannot modify customer identity.';
        }

        return 'This service case is not in an active workflow state.';
    }
}
