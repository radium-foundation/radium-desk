<?php

namespace App\Policies;

use App\Models\CompanyHoliday;
use App\Models\User;

class CompanyHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workforce-calendar.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('workforce-calendar.manage');
    }

    public function delete(User $user, CompanyHoliday $companyHoliday): bool
    {
        return $user->can('workforce-calendar.manage');
    }
}
