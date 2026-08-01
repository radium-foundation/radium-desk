<?php

namespace App\Support\Navigation;

enum NavigationMenu: string
{
    case Dashboard = 'dashboard';
    case Operations = 'operations';
    case MissionControl = 'mission_control';
    case WorkforceManagement = 'workforce_management';
    case Finance = 'finance';
    case Administration = 'administration';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Operations => 'Operations',
            self::MissionControl => 'Mission Control',
            self::WorkforceManagement => 'Workforce Management',
            self::Finance => 'Finance',
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
            self::WorkforceManagement => 'workforce-management.attendance.index',
            self::Finance => 'finance.dashboard',
            self::Administration => 'admin.administration.index',
            self::Personal => 'my-workforce.index',
        };
    }
}
