<?php

namespace App\Casts;

use App\Enums\TeamAvailabilityStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<TeamAvailabilityStatus, TeamAvailabilityStatus|string>
 */
class TeamAvailabilityStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): TeamAvailabilityStatus
    {
        if ($value === null || $value === '' || $value === 'on_leave') {
            return TeamAvailabilityStatus::Offline;
        }

        if ($value instanceof TeamAvailabilityStatus) {
            return $value;
        }

        return TeamAvailabilityStatus::tryFrom((string) $value) ?? TeamAvailabilityStatus::Offline;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof TeamAvailabilityStatus) {
            return $value->value;
        }

        $status = TeamAvailabilityStatus::tryFrom((string) $value) ?? TeamAvailabilityStatus::Offline;

        return $status->value;
    }
}
