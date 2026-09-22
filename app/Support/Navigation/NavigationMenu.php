<?php

namespace App\Support\Navigation;

enum NavigationMenu: string
{
    case HomeDesk = 'home_desk';
    case Commerce = 'commerce';
    case Inventory = 'inventory';
    case Finance = 'finance';
    case ControlAndAdmin = 'control_and_admin';

    public function label(): string
    {
        return match ($this) {
            self::HomeDesk => 'Home / Desk',
            self::Commerce => 'Commerce',
            self::Inventory => 'Inventory',
            self::Finance => 'Finance',
            self::ControlAndAdmin => 'Control & Admin',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HomeDesk => 'bi-house-door',
            self::Commerce => 'bi-bag-check',
            self::Inventory => 'bi-box-seam',
            self::Finance => 'bi-wallet2',
            self::ControlAndAdmin => 'bi-sliders',
        };
    }

    public function homeRoute(): string
    {
        return match ($this) {
            self::HomeDesk => 'dashboard',
            self::Commerce => 'pos.counter.create',
            self::Inventory => 'inventory.stock.index',
            self::Finance => 'finance.dashboard',
            self::ControlAndAdmin => 'admin.administration.index',
        };
    }
}
