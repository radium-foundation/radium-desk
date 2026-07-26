<?php

namespace App\Support\Dashboard;

use App\Data\RecentActivityItem;
use App\Models\AuditLog;

class TeamActivityLabelFormatter
{
    public function labelFor(AuditLog $audit, ?RecentActivityItem $item = null): string
    {
        $labels = config('dashboard-team-activity.activity_labels', []);
        $configured = is_array($labels) ? ($labels[$audit->event] ?? null) : null;

        if (is_string($configured) && $configured !== '') {
            return $this->withReference($configured, $audit->event, $item);
        }

        if ($item !== null && filled($item->title)) {
            return $this->withReference((string) $item->title, $audit->event, $item);
        }

        return 'Activity';
    }

    private function withReference(string $label, string $event, ?RecentActivityItem $item): string
    {
        if (! $this->shouldAttachReference($event) || $item === null) {
            return $label;
        }

        $reference = $item->orderReference ?: $item->incidentReference;

        if (! filled($reference)) {
            return $label;
        }

        return $label.' '.$reference;
    }

    private function shouldAttachReference(string $event): bool
    {
        return in_array($event, [
            'service_case.assigned',
            'service_case.reassigned',
            'service_case.escalated',
        ], true);
    }
}
