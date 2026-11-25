<?php

namespace App\Console\Commands;

use App\Data\Operations\ProductionCriticalAlert;
use App\Services\Operations\ProductionWatchdogService;
use App\Services\Operations\WatchdogHygieneMetricsService;
use Illuminate\Console\Command;

/**
 * Read-only comparison of legacy vs hygiene-enabled watchdog alert counts.
 */
class WatchdogAlertParityCommand extends Command
{
    protected $signature = 'watchdog:alert-parity';

    protected $description = 'Compare legacy and hygiene watchdog alert counts (read-only, no Telegram or gate writes)';

    public function handle(ProductionWatchdogService $watchdog): int
    {
        $original = [
            'radiumbox' => config('ira.watchdog.radiumbox_hygiene_enabled'),
            'dead_letter' => config('ira.watchdog.dead_letter_hygiene_enabled'),
            'bonvoice' => config('ira.watchdog.bonvoice_hygiene_enabled'),
        ];

        $streams = ['radiumbox:sync_failures', 'queue:dead_letter', 'bonvoice:webhook_failures'];

        try {
            config([
                'ira.watchdog.radiumbox_hygiene_enabled' => false,
                'ira.watchdog.dead_letter_hygiene_enabled' => false,
                'ira.watchdog.bonvoice_hygiene_enabled' => false,
            ]);
            $legacyByKey = $this->indexAlerts($watchdog->collectCriticalAlerts(), $streams);

            config([
                'ira.watchdog.radiumbox_hygiene_enabled' => true,
                'ira.watchdog.dead_letter_hygiene_enabled' => true,
                'ira.watchdog.bonvoice_hygiene_enabled' => true,
            ]);
            $hygieneByKey = $this->indexAlerts($watchdog->collectCriticalAlerts(), $streams);

            $hygieneMetrics = app(WatchdogHygieneMetricsService::class)->snapshot();
        } finally {
            config([
                'ira.watchdog.radiumbox_hygiene_enabled' => $original['radiumbox'],
                'ira.watchdog.dead_letter_hygiene_enabled' => $original['dead_letter'],
                'ira.watchdog.bonvoice_hygiene_enabled' => $original['bonvoice'],
            ]);
        }

        $this->line('watchdog_alert_parity');
        foreach ($streams as $key) {
            $legacy = $legacyByKey[$key] ?? null;
            $hygiene = $hygieneByKey[$key] ?? null;
            $this->line(sprintf(
                '%s legacy_count=%d hygiene_count=%d',
                $key,
                (int) ($legacy?->affectedCount ?? 0),
                (int) ($hygiene?->affectedCount ?? 0),
            ));
        }

        $this->line('watchdog_hygiene_metrics='.$this->safeJson($hygieneMetrics));

        return self::SUCCESS;
    }

    /**
     * @param  list<ProductionCriticalAlert>  $alerts
     * @param  list<string>  $keys
     * @return array<string, ProductionCriticalAlert>
     */
    private function indexAlerts(array $alerts, array $keys): array
    {
        $indexed = [];
        foreach ($alerts as $alert) {
            if (in_array($alert->key, $keys, true)) {
                $indexed[$alert->key] = $alert;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
