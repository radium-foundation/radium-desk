<?php

namespace App\Support\Workforce;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Temporary payroll lock for Workforce → Attendance Management.
 *
 * Keeps the existing Spatie gate (team-performance.view) and adds an
 * email allowlist while config workforce.attendance_management.restricted
 * is true. Easy to remove after payroll is finalized.
 *
 * Salaries / Payroll / Finalization: Attendance access + workforce.payroll.manage
 * (Super Admin + Operations Admin). Reopen remains Super Admin-only.
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

    /**
     * Salaries, Payroll screens, employee payroll detail, and finalization.
     * Super Admin + Operations Admin (via workforce.payroll.manage).
     */
    public static function allowsPayroll(?User $user): bool
    {
        if (! self::allows($user)) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_MANAGE);
    }

    /**
     * Future reopen of a finalized payroll month — Super Admin only.
     */
    public static function allowsPayrollReopen(?User $user): bool
    {
        if (! self::allowsPayroll($user)) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_REOPEN);
    }
}
