<?php

namespace App\Support\Navigation;

enum NavigationMenu: string
{
    case Dashboard = 'dashboard';
    case Operations = 'operations';
    case MissionControl = 'mission_control';
    case Administration = 'administration';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Operations => 'Operations',
            self::MissionControl => 'Mission Control',
            self::Administration => 'Administration',
            self::Personal => 'Personal',
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::Dashboard => 'dashboard',
            self::Operations => 'orders.index',
            self::MissionControl => 'admin.platform.index',
            self::Administration => 'admin.administration.index',
            self::Personal => 'my-workforce.index',
        };
    }
}
