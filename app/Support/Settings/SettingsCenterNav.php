<?php

namespace App\Support\Settings;

use App\Models\SettingProduct;
use App\Models\SystemSetting;
use App\Support\Administration\PlatformConfigurationAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

final class SettingsCenterNav
{
    /**
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public static function groups(?string $activeKey = null): array
    {
        $user = auth()->user();
        $canViewApplication = $user?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
            && Gate::check('viewAny', SettingProduct::class);
        $canViewSystem = Gate::check('viewAny', SystemSetting::class);
        $canManagePlatformConfiguration = PlatformConfigurationAccess::canManage($user);

        $groups = [];

        $systemItems = [];

        if ($canManagePlatformConfiguration) {
            $systemItems[] = self::item(
                'overview',
                'Configuration Overview',
                'layout-dashboard',
                route('admin.platform-configuration.index').'#section-overview',
                $activeKey === 'overview',
            );
        }

        if ($canViewApplication) {
            $systemItems[] = self::item(
                'general',
                'General',
                'settings',
                route('settings.index', ['tab' => 'general']),
                $activeKey === 'general',
            );
            $systemItems[] = self::item(
                'application',
                'Application',
                'monitor',
                route('settings.index', ['tab' => 'products']),
                in_array($activeKey, ['application', 'products', 'device-models', 'sources', 'assignment', 'sla', 'search'], true),
            );
        }

        if ($systemItems !== []) {
            $groups[] = ['label' => 'System', 'items' => $systemItems];
        }

        if ($canViewSystem) {
            $groups[] = [
                'label' => 'Operations',
                'items' => [
                    self::item(
                        'operational-center',
                        'Operational Center',
                        'zap',
                        route('admin.system-settings.index').'#section-operational-center',
                        $activeKey === 'operational-center',
                    ),
                ],
            ];

            $configureItems = [
                self::item(
                    'notifications',
                    'Notifications',
                    'bell',
                    $canViewApplication
                        ? route('settings.index', ['tab' => 'notifications'])
                        : route('admin.system-settings.index').'#category-notifications',
                    in_array($activeKey, ['notifications', 'category-notifications'], true),
                ),
            ];

            if ($canManagePlatformConfiguration) {
                $configureItems[] = self::item(
                    'environment',
                    'Environment',
                    'info',
                    route('admin.platform-configuration.index').'#category-system',
                    in_array($activeKey, ['diagnostics', 'category-system', 'environment'], true),
                );
                $configureItems[] = self::item(
                    'advanced',
                    'Advanced',
                    'wrench',
                    route('admin.platform-configuration.index').'#section-advanced',
                    $activeKey === 'advanced',
                );
            }

            $groups[] = [
                'label' => 'Configure',
                'items' => $configureItems,
            ];

            if ($canManagePlatformConfiguration) {
                $groups[] = [
                    'label' => 'Observe',
                    'items' => [
                        self::item(
                            'platform-monitoring',
                            'Platform monitoring',
                            'heart-pulse',
                            route('admin.platform.index'),
                            $activeKey === 'platform-monitoring',
                        ),
                    ],
                ];
            }
        } elseif ($canViewApplication) {
            $groups[] = [
                'label' => 'Platform',
                'items' => [
                    self::item(
                        'notifications',
                        'Notifications',
                        'bell',
                        route('settings.index', ['tab' => 'notifications']),
                        $activeKey === 'notifications',
                    ),
                ],
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $key, string $label, string $icon, string $url, bool $active): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'active' => $active,
        ];
    }

    public static function resolveActiveKey(): string
    {
        if (request()->routeIs('admin.platform-configuration.*')) {
            return 'overview';
        }

        if (request()->routeIs('admin.system-settings.*')) {
            return 'operational-center';
        }

        return match (request('tab', 'general')) {
            'general' => 'general',
            'notifications' => 'notifications',
            default => 'application',
        };
    }
}
