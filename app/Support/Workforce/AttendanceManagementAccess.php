<?php

namespace App\Support\Workforce;

use App\Models\User;

/**
 * Temporary payroll lock for Workforce → Attendance Management.
 *
 * Keeps the existing Spatie gate (team-performance.view) and adds an
 * email allowlist while config workforce.attendance_management.restricted
 * is true. Easy to remove after payroll is finalized.
 */
final class AttendanceManagementAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $user->can('team-performance.view')) {
            return false;
        }

        if (! config('workforce.attendance_management.restricted', false)) {
            return true;
        }

        $allowed = config('workforce.attendance_management.allowed_emails', []);

        return in_array(strtolower((string) $user->email), $allowed, true);
    }
}
