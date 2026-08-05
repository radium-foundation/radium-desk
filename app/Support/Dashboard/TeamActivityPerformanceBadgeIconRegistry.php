<?php

namespace App\Support\Dashboard;

use App\Support\Settings\SettingsIcon;

/**
 * Maps Team Activity performance badge keys to outline SVG icons.
 *
 * Presentation only — resolver and PI logic stay unchanged.
 * Icons come from {@see SettingsIcon} (existing Radium Desk SVG library).
 */
final class TeamActivityPerformanceBadgeIconRegistry
{
    /**
     * @return array<string, string> badge key => SettingsIcon name
     */
    public static function map(): array
    {
        return [
            'extra_contribution' => 'sparkles',
            'exceptional_day' => 'zap',
            'critical_work' => 'alert-triangle',
            'team_helper' => 'heart-pulse',
        ];
    }

    public static function iconNameFor(string $badgeKey): string
    {
        return self::map()[$badgeKey] ?? 'circle';
    }

    public static function render(string $badgeKey): string
    {
        return SettingsIcon::render(
            self::iconNameFor($badgeKey),
            'team-activity-performance-badge__icon',
        );
    }
}
