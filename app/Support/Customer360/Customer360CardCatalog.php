<?php

namespace App\Support\Customer360;

use App\Data\Context\ContextBadge;
use App\Data\Context\Customer360CardDefinition;
use App\Enums\ContextScope;
use App\Support\Context\ContextTransparency;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Internal catalog of Customer 360 cards and their intended BR-02 scopes.
 *
 * Phase 1: annotation + metadata only. Does not change rendering or APIs.
 */
final class Customer360CardCatalog
{
    public const OPERATIONS_HEADER = 'operations_header';

    public const COMMERCIAL_STATE = 'commercial_state';

    public const QUICK_ACTIONS = 'quick_actions';

    public const EXECUTIVE_SUMMARY = 'executive_summary';

    public const HEALTH_CARD = 'health_card';

    public const HEALTH_RECENT_COMMUNICATION = 'health_recent_communication';

    public const HEALTH_RECENT_CALLS = 'health_recent_calls';

    public const CUSTOMER_SUMMARY = 'customer_summary';

    public const COMMUNICATION_ACTIONS = 'communication_actions';

    public const WAITING_STATE = 'waiting_state';

    public const SUPPORT_APPOINTMENTS = 'support_appointments';

    public const DEVICE_SECTION = 'device_section';

    public const DEVICE_SERIAL = 'device_serial';

    public const DEVICE_WARRANTY = 'device_warranty';

    public const ACTIVE_SERVICES = 'active_services';

    public const SYNC_HISTORY = 'sync_history';

    public const TIMELINE = 'timeline';

    public const IRA_PANEL = 'ira_panel';

    public const IRA_AI_TAB = 'ira_ai_tab';

    public const REFUND_ACTION = 'refund_action';

    public const PREVIOUS_REFUNDS = 'previous_refunds';

    public const PREVIOUS_ORDERS = 'previous_orders';

    public const PREVIOUS_COMMUNICATION = 'previous_communication';

    public const OVERFLOW_MENU = 'overflow_menu';

    /**
     * @return list<Customer360CardDefinition>
     */
    public static function definitions(): array
    {
        return [
            new Customer360CardDefinition(
                key: self::OPERATIONS_HEADER,
                name: 'Operations Header',
                intendedScope: ContextScope::Case,
                surface: 'components/c360/operations-header',
                notes: 'Active case shell: reference, owner, status.',
            ),
            new Customer360CardDefinition(
                key: self::COMMERCIAL_STATE,
                name: 'Commercial State',
                intendedScope: ContextScope::Case,
                surface: 'components/c360/commercial-state',
                notes: 'Sticky commercial posture banner (BR-04). Always above tabs.',
            ),
            new Customer360CardDefinition(
                key: self::QUICK_ACTIONS,
                name: 'Quick Actions',
                intendedScope: ContextScope::Case,
                surface: 'components/c360/quick-action-toolbar',
            ),
            new Customer360CardDefinition(
                key: self::EXECUTIVE_SUMMARY,
                name: 'Executive Summary / IRA Overview',
                intendedScope: ContextScope::Case,
                surface: 'partials/executive-summary',
                notes: 'Authoritative case reasoning surface (BR-02).',
            ),
            new Customer360CardDefinition(
                key: self::HEALTH_CARD,
                name: 'Health Card',
                intendedScope: ContextScope::Case,
                surface: 'partials/health-card',
                notes: 'Intended case health; today still blends customer metrics (BR-02 debt).',
            ),
            new Customer360CardDefinition(
                key: self::HEALTH_RECENT_COMMUNICATION,
                name: 'Recent Communication',
                intendedScope: ContextScope::Case,
                surface: 'partials/health-card-communication',
                notes: 'Intended case-scoped; today phone-wide (BR-02). Customer history counterpart below.',
            ),
            new Customer360CardDefinition(
                key: self::HEALTH_RECENT_CALLS,
                name: 'Recent Calls',
                intendedScope: ContextScope::Customer,
                surface: 'health-card / last_call',
                notes: 'Phone-wide call summary — Customer History (BR-02).',
            ),
            new Customer360CardDefinition(
                key: self::CUSTOMER_SUMMARY,
                name: 'Customer Summary Metrics',
                intendedScope: ContextScope::Customer,
                surface: 'partials/customer-summary',
                notes: 'Lifetime orders / open cases across phone.',
            ),
            new Customer360CardDefinition(
                key: self::COMMUNICATION_ACTIONS,
                name: 'Communication Actions',
                intendedScope: ContextScope::Case,
                surface: 'partials/communication-actions',
            ),
            new Customer360CardDefinition(
                key: self::WAITING_STATE,
                name: 'Waiting State',
                intendedScope: ContextScope::Case,
                surface: 'partials/waiting-state-card',
            ),
            new Customer360CardDefinition(
                key: self::SUPPORT_APPOINTMENTS,
                name: 'Support Appointments',
                intendedScope: ContextScope::Case,
                surface: 'partials/support-appointments',
            ),
            new Customer360CardDefinition(
                key: self::DEVICE_SECTION,
                name: 'Device Section',
                intendedScope: ContextScope::Device,
                surface: 'partials/device-section',
            ),
            new Customer360CardDefinition(
                key: self::DEVICE_SERIAL,
                name: 'Serial',
                intendedScope: ContextScope::Device,
                surface: 'device-section / serial',
            ),
            new Customer360CardDefinition(
                key: self::DEVICE_WARRANTY,
                name: 'Warranty / RD Service',
                intendedScope: ContextScope::Order,
                surface: 'active-services / warranty',
            ),
            new Customer360CardDefinition(
                key: self::ACTIVE_SERVICES,
                name: 'Active Services',
                intendedScope: ContextScope::Order,
                surface: 'partials/active-services',
            ),
            new Customer360CardDefinition(
                key: self::SYNC_HISTORY,
                name: 'RadiumBox Sync History',
                intendedScope: ContextScope::Order,
                surface: 'partials/sync-history',
            ),
            new Customer360CardDefinition(
                key: self::TIMELINE,
                name: 'Timeline',
                intendedScope: ContextScope::Case,
                surface: 'partials/timeline-tab',
                notes: 'Intended case/order timeline; phone-scoped sources are BR-02 debt.',
            ),
            new Customer360CardDefinition(
                key: self::IRA_PANEL,
                name: 'IRA Panel',
                intendedScope: ContextScope::Case,
                surface: 'IRA overview / command center',
            ),
            new Customer360CardDefinition(
                key: self::IRA_AI_TAB,
                name: 'IRA AI Tab',
                intendedScope: ContextScope::Case,
                surface: 'partials/ai-tab',
            ),
            new Customer360CardDefinition(
                key: self::REFUND_ACTION,
                name: 'Refund',
                intendedScope: ContextScope::Case,
                surface: 'overflow / refund-request',
                notes: 'Current order/case refund actions.',
            ),
            new Customer360CardDefinition(
                key: self::PREVIOUS_REFUNDS,
                name: 'Previous Refunds',
                intendedScope: ContextScope::Customer,
                surface: 'customer-history (future)',
                notes: 'Not a dedicated panel yet — catalogued for BR-02 Customer History.',
            ),
            new Customer360CardDefinition(
                key: self::PREVIOUS_ORDERS,
                name: 'Previous Orders',
                intendedScope: ContextScope::Customer,
                surface: 'customer-history (future)',
            ),
            new Customer360CardDefinition(
                key: self::PREVIOUS_COMMUNICATION,
                name: 'Previous Communication',
                intendedScope: ContextScope::Customer,
                surface: 'customer-history (future)',
            ),
            new Customer360CardDefinition(
                key: self::OVERFLOW_MENU,
                name: 'Overflow Menu',
                intendedScope: ContextScope::Case,
                surface: 'partials/overflow-menu',
            ),
        ];
    }

    /**
     * @return Collection<string, Customer360CardDefinition>
     */
    public static function keyed(): Collection
    {
        return collect(self::definitions())->keyBy(
            fn (Customer360CardDefinition $definition): string => $definition->key,
        );
    }

    public static function get(string $key): Customer360CardDefinition
    {
        $definition = self::keyed()->get($key);

        if (! $definition instanceof Customer360CardDefinition) {
            throw new InvalidArgumentException("Unknown Customer360 card key [{$key}].");
        }

        return $definition;
    }

    public static function intendedScope(string $key): ContextScope
    {
        return self::get($key)->intendedScope;
    }

    /**
     * Badge metadata when context transparency is enabled; otherwise null.
     */
    public static function badgeFor(string $key): ?ContextBadge
    {
        if (! ContextTransparency::enabled()) {
            return null;
        }

        return self::get($key)->badge();
    }

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     intended_scope: string,
     *     surface: string,
     *     notes: ?string,
     *     badge: array{scope: string, label: string, icon: ?string, color_token: ?string}
     * }>
     */
    public static function export(): array
    {
        return array_map(
            fn (Customer360CardDefinition $definition): array => $definition->toArray(),
            self::definitions(),
        );
    }

    /**
     * @return Collection<int, Customer360CardDefinition>
     */
    public static function forScope(ContextScope $scope): Collection
    {
        return collect(self::definitions())
            ->filter(fn (Customer360CardDefinition $definition): bool => $definition->intendedScope === $scope)
            ->values();
    }
}
