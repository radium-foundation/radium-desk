<?php

namespace App\Services\Platform\Alerts;

use App\Data\Platform\PlatformAlert;
use App\Enums\PlatformAlertSeverity;
use Illuminate\Support\Carbon;

class PlatformAlertAggregator
{
    public function __construct(
        private readonly PlatformAlertRegistry $registry,
    ) {}

    /**
     * @return list<PlatformAlert>
     */
    public function collect(): array
    {
        $alerts = [];

        foreach ($this->registry->all() as $contributor) {
            foreach ($contributor->alerts() as $alert) {
                $alerts[] = $alert;
            }
        }

        return $this->sort($this->deduplicate($alerts));
    }

    /**
     * @param  list<PlatformAlert>  $alerts
     * @return list<PlatformAlert>
     */
    public function deduplicate(array $alerts): array
    {
        /** @var array<string, list<PlatformAlert>> $groups */
        $groups = [];

        foreach ($alerts as $alert) {
            $groups[$alert->groupKey][] = $alert;
        }

        $deduped = [];

        foreach ($groups as $groupKey => $groupAlerts) {
            if (count($groupAlerts) === 1) {
                $deduped[] = $groupAlerts[0];
                continue;
            }

            $worst = $groupAlerts[0];
            foreach ($groupAlerts as $candidate) {
                if ($candidate->severity->sortOrder() < $worst->severity->sortOrder()) {
                    $worst = $candidate;
                }
            }

            $related = array_map(
                static fn (PlatformAlert $alert): array => [
                    'id' => $alert->id,
                    'title' => $alert->title,
                    'summary' => $alert->summary,
                    'severity' => $alert->severity->value,
                ],
                $groupAlerts,
            );

            $latest = null;
            foreach ($groupAlerts as $alert) {
                if ($alert->lastUpdated === null) {
                    continue;
                }
                if ($latest === null || $alert->lastUpdated->greaterThan($latest)) {
                    $latest = $alert->lastUpdated;
                }
            }

            $count = count($groupAlerts);
            $title = $worst->title;
            $summary = $count > 1
                ? sprintf('%d related issues', $count)
                : $worst->summary;

            // Prefer source family title when grouping integration items under one key.
            if ($count > 1 && str_contains($groupKey, ':') === false) {
                $title = ucfirst(str_replace('_', ' ', $groupKey));
            }

            $deduped[] = new PlatformAlert(
                id: 'group:'.$groupKey,
                source: $worst->source,
                groupKey: $groupKey,
                title: $title,
                summary: $summary,
                severity: $worst->severity,
                status: $worst->severity->label(),
                lastUpdated: $latest,
                count: $count,
                link: $worst->link,
                related: $related,
            );
        }

        return $deduped;
    }

    /**
     * @param  list<PlatformAlert>  $alerts
     * @return list<PlatformAlert>
     */
    public function sort(array $alerts): array
    {
        usort($alerts, static function (PlatformAlert $a, PlatformAlert $b): int {
            $severity = $a->severity->sortOrder() <=> $b->severity->sortOrder();
            if ($severity !== 0) {
                return $severity;
            }

            return strcasecmp($a->title, $b->title);
        });

        return $alerts;
    }

    /**
     * Actionable alerts (exclude healthy/disabled noise from the critical rail).
     *
     * @param  list<PlatformAlert>  $alerts
     * @return list<PlatformAlert>
     */
    public function actionable(array $alerts): array
    {
        return array_values(array_filter(
            $alerts,
            static fn (PlatformAlert $alert): bool => in_array(
                $alert->severity,
                [PlatformAlertSeverity::Critical, PlatformAlertSeverity::Warning, PlatformAlertSeverity::Information],
                true,
            ),
        ));
    }
}
