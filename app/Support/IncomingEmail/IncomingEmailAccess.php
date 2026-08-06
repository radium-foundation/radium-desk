<?php

namespace App\Support\IncomingEmail;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Permission-based access for Email Intake / Learning Center.
 *
 * Authorization uses Spatie permissions only — never role name checks.
 */
final class IncomingEmailAccess
{
    public static function featureEnabled(): bool
    {
        return (bool) config('inbound_email.enabled');
    }

    public static function allowsView(?User $user): bool
    {
        if ($user === null || ! self::featureEnabled()) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_VIEW);
    }

    public static function allowsManage(?User $user): bool
    {
        if ($user === null || ! self::featureEnabled()) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_MANAGE);
    }
}
