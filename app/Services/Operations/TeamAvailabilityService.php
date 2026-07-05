<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TeamAvailabilityService
{
    public function statusFor(User $user): TeamAvailabilityStatus
    {
        $stored = $user->availability_status;

        if ($stored instanceof TeamAvailabilityStatus) {
            return $stored;
        }

        if (is_string($stored) && $stored !== '') {
            return TeamAvailabilityStatus::tryFrom($stored) ?? TeamAvailabilityStatus::Offline;
        }

        return TeamAvailabilityStatus::Offline;
    }

    public function isOnLeave(User $user, ?Carbon $date = null): bool
    {
        if ($this->statusFor($user) === TeamAvailabilityStatus::OnLeave) {
            return true;
        }

        if ($user->leave_start_date === null) {
            return false;
        }

        $date ??= now()->startOfDay();
        $start = $user->leave_start_date->startOfDay();
        $end = $user->leave_end_date?->endOfDay();

        if ($date->lt($start)) {
            return false;
        }

        if ($end !== null && $date->gt($end)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user): array
    {
        $status = $this->statusFor($user);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'updated_at' => $user->availability_updated_at?->toIso8601String(),
            'leave_start_date' => $user->leave_start_date?->toDateString(),
            'leave_end_date' => $user->leave_end_date?->toDateString(),
            'on_leave' => $this->isOnLeave($user),
        ];
    }
}
