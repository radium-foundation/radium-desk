<?php

namespace App\Support\Navigation;

enum NavigationMenu: string
{
    case Operations = 'operations';
    case ControlCenter = 'control_center';
    case Administration = 'administration';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::ControlCenter => 'Control Center',
            self::Administration => 'Administration',
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::Operations => 'dashboard',
            self::ControlCenter => 'admin.operations.index',
            self::Administration => 'admin.administration.index',
            self::SuperAdmin => 'admin.platform.index',
        };
    }
}
