<?php

namespace App\Enums;

/**
 * Authoritative information scopes for Radium Desk (BR-03).
 *
 * Features must declare which context they belong to — never infer it.
 * See docs/br-03-context-transparency.md and docs/br-02-case-customer-context-separation.md.
 */
enum ContextScope: string
{
    case Case = 'case';
    case Order = 'order';
    case Device = 'device';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Case => 'Case',
            self::Order => 'Order',
            self::Device => 'Device',
            self::Customer => 'Customer',
        };
    }

    /**
     * Optional presentation color token for future ContextBadge UI.
     */
    public function colorToken(): string
    {
        return match ($this) {
            self::Case => 'context-case',
            self::Order => 'context-order',
            self::Device => 'context-device',
            self::Customer => 'context-customer',
        };
    }

    /**
     * Optional Bootstrap-icon class for future ContextBadge UI.
     */
    public function defaultIcon(): string
    {
        return match ($this) {
            self::Case => 'bi-ticket-detailed',
            self::Order => 'bi-receipt',
            self::Device => 'bi-cpu',
            self::Customer => 'bi-person',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
