<?php

namespace App\Support\CashBook;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class CashBookAccess
{
    public static function allowsView(?User $user): bool
    {
        return $user !== null && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW);
    }

    public static function allowsCreate(?User $user): bool
    {
        return $user !== null
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_CREATE);
    }

    public static function allowsManage(?User $user): bool
    {
        return $user !== null
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_MANAGE);
    }

    public static function allowsBackdate(?User $user): bool
    {
        return $user !== null
            && $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public static function allowsHistoricalImport(?User $user): bool
    {
        return $user !== null
            && $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_HISTORICAL);
    }

    /**
     * @throws ValidationException
     */
    public static function assertEntryDateAllowed(User $user, string $entryDate, ?string $backdateReason = null): void
    {
        try {
            $date = Carbon::parse($entryDate)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'entry_date' => 'Enter a valid date.',
            ]);
        }

        $today = now()->startOfDay();

        if ($date->greaterThan($today)) {
            throw ValidationException::withMessages([
                'entry_date' => 'Future dates are not allowed.',
            ]);
        }

        if ($date->equalTo($today)) {
            return;
        }

        // Back-dated.
        if (! self::allowsBackdate($user)) {
            throw ValidationException::withMessages([
                'entry_date' => 'Only Super Admin may back-date Cash Book entries.',
            ]);
        }

        $reason = trim((string) $backdateReason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'backdate_reason' => 'A reason is required for back-dated entries (e.g. Late entry, Forgot yesterday).',
            ]);
        }
    }
}
