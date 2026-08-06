<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Route;

/**
 * Links-only Tools & Diagnostics catalog for Platform.
 */
class PlatformToolsCatalogService
{
    /**
     * @return list<array{title: string, description?: string, links: list<array{label: string, url: string}>}>
     */
    public function groups(): array
    {
        $groups = [
            [
                'title' => 'Platform Monitoring',
                'description' => 'Jump to in-page Platform zones — no duplicate dashboards.',
                'links' => array_values(array_filter([
                    $this->link('Platform Dashboard', 'admin.platform.index'),
                    $this->anchor('Platform Health', 'admin.platform.index', 'platform-health'),
                    $this->anchor('Integration Health', 'admin.platform.index', 'platform-zone-integration_health'),
                    $this->anchor('Email Operations', 'admin.platform.index', 'platform-zone-email_operations'),
                    $this->anchor('Performance', 'admin.platform.index', 'platform-zone-performance'),
                    $this->anchor('Automation', 'admin.platform.index', 'platform-zone-automation'),
                    $this->anchor('Critical Alerts', 'admin.platform.index', 'platform-zone-critical_alerts'),
                ])),
            ],
            [
                'title' => 'Automation & Queues',
                'links' => array_values(array_filter([
                    $this->link('Automation Health', 'admin.operations.automation-health'),
                    $this->link('Automation Pipeline', 'admin.automation.index'),
                    $this->link('Ops Automation Hub', 'admin.operations.index', ['hub_tab' => 'automation']),
                ])),
            ],
            [
                'title' => 'Payments & Webhooks',
                'links' => array_values(array_filter([
                    $this->link('Webhook Explorer', 'cashfree.webhook-explorer.index'),
                    $this->link('Refund Queue', 'refunds.index', ['status' => 'pending']),
                ])),
            ],
            [
                'title' => 'Audit & Configuration',
                'links' => array_values(array_filter([
                    $this->link('Audit Logs', 'audit-logs.index'),
                    $this->link('System Settings', 'admin.system-settings.index'),
                    $this->link('Gmail Sync Logs', 'admin.gmail.logs'),
                    $this->link('Administration', 'admin.administration.index'),
                ])),
            ],
            [
                'title' => 'Recovery Utilities',
                'description' => 'Operational recovery actions remain in Operations.',
                'links' => array_values(array_filter([
                    $this->link('Operations Control Center', 'admin.operations.index'),
                    $this->link('Ops System Tab', 'admin.operations.index', ['hub_tab' => 'system']),
                    $this->link('Gmail Failed Messages', 'admin.gmail.failed-messages'),
                    $this->link('Email Intake Queues', 'admin.incoming-emails.index'),
                ])),
            ],
        ];

        return array_values(array_filter(
            $groups,
            static fn (array $group): bool => ($group['links'] ?? []) !== [],
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{label: string, url: string}|null
     */
    private function link(string $label, string $route, array $params = []): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return [
            'label' => $label,
            'url' => route($route, $params),
        ];
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private function anchor(string $label, string $route, string $fragment): ?array
    {
        $base = $this->link($label, $route);
        if ($base === null) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $base['url'].'#'.$fragment,
        ];
    }
}
