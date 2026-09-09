<?php

namespace App\Support\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Gate helpers for the Finance module hub and workspace tabs.
 *
 * Keep checks on Spatie permissions so later create/review/post workflows
 * can layer on without changing the Finance nav structure.
 */
final class FinanceAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW);
    }

    public static function allowsPermission(?User $user, string $permission): bool
    {
        if (! self::allows($user)) {
            return false;
        }

        return $user->can($permission);
    }

    public static function allowsInvoices(?User $user): bool
    {
        return self::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW);
    }

    public static function allowsInvoiceIssue(?User $user): bool
    {
        return self::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE);
    }

    public static function allowsReportExport(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_FINANCE_REPORTS_EXPORT)
            || $user->can(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_ISSUE);
    }

    public static function allowsParties(?User $user): bool
    {
        return self::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_VIEW);
    }

    public static function allowsPartyManage(?User $user): bool
    {
        return self::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_MANAGE);
    }

    public static function allowsPartyBank(?User $user): bool
    {
        return self::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_BANK_VIEW);
    }
}
