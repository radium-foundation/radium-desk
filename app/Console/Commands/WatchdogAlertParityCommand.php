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
        $streams = ['radiumbox:sync_failures', 'queue:dead_letter', 'bonvoice:webhook_failures'];

        $legacyByKey = [];
        $hygieneByKey = [];
        $hygieneMetrics = [];

        try {
            $legacyByKey = $this->indexAlerts($watchdog->collectParityAlerts(false), $streams);
            $hygieneByKey = $this->indexAlerts($watchdog->collectParityAlerts(true), $streams);
            $hygieneMetrics = app(WatchdogHygieneMetricsService::class)->snapshot();
        } catch (\Throwable $e) {
            throw $e;
        }

        $this->line('watchdog_alert_parity');
        $this->line(sprintf(
            'deployed_feature_flags radiumbox=%s dead_letter=%s bonvoice=%s',
            config('ira.watchdog.radiumbox_hygiene_enabled', false) ? 'true' : 'false',
            config('ira.watchdog.dead_letter_hygiene_enabled', false) ? 'true' : 'false',
            config('ira.watchdog.bonvoice_hygiene_enabled', false) ? 'true' : 'false',
        ));
        $this->line(sprintf(
            'parity_compare_mode legacy=collectParityAlerts(false) hypothetical_hygiene=collectParityAlerts(true) actionable_hours=%d stale_days=%d',
            (int) config('ira.watchdog.actionable_hours', 48),
            (int) config('ira.watchdog.stale_days', 30),
        ));
        foreach ($streams as $key) {
            $legacy = $legacyByKey[$key] ?? null;
            $hygiene = $hygieneByKey[$key] ?? null;
            $this->line(sprintf(
                '%s legacy_critical_count=%d hygiene_critical_count=%d',
                $key,
                (int) ($legacy?->affectedCount ?? 0),
                (int) ($hygiene?->affectedCount ?? 0),
            ));
        }

        $radiumBox = is_array($hygieneMetrics['radiumbox_failed_syncs'] ?? null)
            ? $hygieneMetrics['radiumbox_failed_syncs']
            : [];
        $this->line(sprintf(
            'radiumbox_buckets actionable_48h=%d aging_48h_to_30d=%d debt_over_30d=%d excluded_cfpay=%d legacy_total=%d',
            (int) ($radiumBox['actionable'] ?? 0),
            (int) ($radiumBox['aging'] ?? 0),
            (int) ($radiumBox['debt'] ?? 0),
            (int) ($radiumBox['excluded_cfpay'] ?? 0),
            (int) ($radiumBox['legacy_total'] ?? 0),
        ));

        $deadLetter = is_array($hygieneMetrics['queue_dead_letter'] ?? null)
            ? $hygieneMetrics['queue_dead_letter']
            : [];
        $this->line(sprintf(
            'dead_letter_buckets actionable_48h=%d historical_over_30d=%d recovered=%d duplicate=%d unresolvable_recent=%d legacy_total=%d',
            (int) ($deadLetter['actionable'] ?? 0),
            (int) ($deadLetter['historical'] ?? 0),
            (int) ($deadLetter['recovered'] ?? 0),
            (int) ($deadLetter['duplicate'] ?? 0),
            (int) ($deadLetter['unresolvable_recent'] ?? 0),
            (int) ($deadLetter['legacy_total'] ?? 0),
        ));

        $bonvoice = is_array($hygieneMetrics['bonvoice_webhook_failures_24h'] ?? null)
            ? $hygieneMetrics['bonvoice_webhook_failures_24h']
            : [];
        $this->line(sprintf(
            'bonvoice_terminal_24h legacy_failed=%d downgraded_no_channel=%d critical_failed=%d',
            (int) ($bonvoice['legacy_failed_24h'] ?? 0),
            (int) ($bonvoice['downgraded_no_channel_24h'] ?? 0),
            (int) ($bonvoice['critical_failed_24h'] ?? 0),
        ));

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
