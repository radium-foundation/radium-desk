<?php

namespace App\Support\Navigation;

enum NavigationMenu: string
{
    case Home = 'home';
    case CustomersAndService = 'customers_and_service';
    case SalesAndPurchasing = 'sales_and_purchasing';
    case Inventory = 'inventory';
    case Finance = 'finance';
    case Workforce = 'workforce';
    case ControlAndAdmin = 'control_and_admin';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::CustomersAndService => 'Customers & Service',
            self::SalesAndPurchasing => 'Sales & Purchasing',
            self::Inventory => 'Inventory',
            self::Finance => 'Finance',
            self::Workforce => 'Workforce',
            self::ControlAndAdmin => 'Control & Admin',
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::Home => 'dashboard',
            self::CustomersAndService => 'dashboard',
            self::SalesAndPurchasing => 'pos.counter.create',
            self::Inventory => 'inventory.stock.index',
            self::Finance => 'finance.dashboard',
            self::Workforce => 'workforce-management.attendance.index',
            self::ControlAndAdmin => 'admin.administration.index',
        };
    }
}
