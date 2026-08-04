<?php

namespace App\Services\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Enums\OperationsHealthStatus;
use App\Services\Interakt\InteraktTemplateConfigurationValidator;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Operations\OperationsGmailHealthService;
use App\Services\Operations\OperationsRadiumBoxHealthService;
use App\Services\SystemSettingsService;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Integration Health overview + per-item diagnostics for Platform.
 *
 * Overview cards never embed diagnostics. Expand loads one integration only.
 * Each integration has an independent cache entry.
 */
class PlatformIntegrationHealthOverviewService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_INTEGRATION_OVERVIEW;

    public const ITEM_CACHE_PREFIX = PlatformCachePolicy::KEY_INTEGRATION_ITEM_PREFIX;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_2;

    /**
     * @var list<string>
     */
    public const INTEGRATION_KEYS = [
        'radiumbox',
        'cashfree',
        'gmail',
        'interakt',
        'zeptomail',
        'telegram',
        'meta_flow',
    ];

    /**
     * @var array<string, string>
     */
    public const INTEGRATION_LABELS = [
        'radiumbox' => 'RadiumBox',
        'cashfree' => 'Cashfree',
        'gmail' => 'Gmail',
        'interakt' => 'Interakt',
        'zeptomail' => 'ZeptoMail',
        'telegram' => 'Telegram',
        'meta_flow' => 'Meta',
    ];

    public function __construct(
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealth,
        private readonly OperationsCashfreeHealthService $cashfreeHealth,
        private readonly OperationsGmailHealthService $gmailHealth,
        private readonly InteraktTemplateConfigurationValidator $interaktTemplates,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function isKnownKey(string $key): bool
    {
        return in_array($key, self::INTEGRATION_KEYS, true);
    }

    public function itemCacheKey(string $key): string
    {
        return self::ITEM_CACHE_PREFIX.$key;
    }

    /**
     * Cache-only overview for first paint / Administration. Never probes.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     overall_status: string,
     *     overall_status_label: string,
     *     generated_at: ?string,
     *     available: bool
     * }
     */
    public function cachedOverview(): array
    {
        $items = [];
        $anyAvailable = false;
        $latestAt = null;

        foreach (self::INTEGRATION_KEYS as $key) {
            $cached = $this->cachedItem($key);

            if ($cached === null) {
                $items[] = $this->loadingItem($key);
                continue;
            }

            $anyAvailable = true;
            $items[] = $cached;
            $latestAt = $this->maxTimestamp($latestAt, $cached['updated_at'] ?? null);
        }

        if (! $anyAvailable) {
            $aggregate = Cache::get(self::CACHE_KEY);
            PlatformCacheAudit::read(
                service: self::class,
                method: 'cachedOverview',
                cacheKey: self::CACHE_KEY,
                payload: is_array($aggregate) ? $aggregate : null,
                hit: is_array($aggregate) && isset($aggregate['items'], $aggregate['overall_status']),
            );

            if (is_array($aggregate) && isset($aggregate['items'], $aggregate['overall_status'])) {
                return [
                    'items' => array_values($aggregate['items']),
                    'overall_status' => (string) $aggregate['overall_status'],
                    'overall_status_label' => (string) ($aggregate['overall_status_label'] ?? 'Unknown'),
                    'generated_at' => isset($aggregate['generated_at']) ? (string) $aggregate['generated_at'] : null,
                    'available' => true,
                ];
            }

            return [
                'items' => array_map(fn (string $key): array => $this->loadingItem($key), self::INTEGRATION_KEYS),
                'overall_status' => IntegrationHealthStatus::Loading->value,
                'overall_status_label' => IntegrationHealthStatus::Loading->label(),
                'generated_at' => null,
                'available' => false,
            ];
        }

        return $this->composeOverview($items, $latestAt, available: true);
    }

    /**
     * Fresh overview: refresh each integration independently.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     overall_status: string,
     *     overall_status_label: string,
     *     generated_at: string,
     *     available: bool
     * }
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();

            if ($cached['available']) {
                return [
                    'items' => $cached['items'],
                    'overall_status' => $cached['overall_status'],
                    'overall_status_label' => $cached['overall_status_label'],
                    'generated_at' => $cached['generated_at'] ?? now()->toIso8601String(),
                    'available' => true,
                ];
            }
        }

        $items = [];

        foreach (self::INTEGRATION_KEYS as $key) {
            $items[] = $this->refreshItem($key);
        }

        $composed = $this->composeOverview($items, now()->toIso8601String(), available: true);

        $old = Cache::get(self::CACHE_KEY);
        $new = [
            'items' => $composed['items'],
            'overall_status' => $composed['overall_status'],
            'overall_status_label' => $composed['overall_status_label'],
            'generated_at' => $composed['generated_at'],
        ];
        PlatformCacheAudit::write(
            service: self::class,
            method: 'overview',
            cacheKey: self::CACHE_KEY,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $new,
        );
        Cache::put(self::CACHE_KEY, $new, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $composed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cachedItem(string $key): ?array
    {
        if (! $this->isKnownKey($key)) {
            return null;
        }

        $cached = Cache::get($this->itemCacheKey($key));
        PlatformCacheAudit::read(
            service: self::class,
            method: 'cachedItem',
            cacheKey: $this->itemCacheKey($key),
            payload: is_array($cached) ? $cached : null,
            hit: is_array($cached) && isset($cached['key'], $cached['status']),
        );

        return is_array($cached) && isset($cached['key'], $cached['status']) ? $cached : null;
    }

    /**
     * Refresh one integration overview card. Isolated — failures do not clear other caches.
     *
     * @return array<string, mixed>
     */
    public function refreshItem(string $key): array
    {
        if (! $this->isKnownKey($key)) {
            return $this->unavailableItem($key, 'Unknown integration.');
        }

        try {
            $item = match ($key) {
                'radiumbox' => $this->radiumBoxItem(),
                'cashfree' => $this->cashfreeItem(),
                'gmail' => $this->gmailItem(),
                'interakt' => $this->interaktItem(),
                'zeptomail' => $this->zeptomailItem(),
                'telegram' => $this->telegramItem(),
                'meta_flow' => $this->metaFlowItem(),
                default => $this->unavailableItem($key, 'Unknown integration.'),
            };

            $oldItem = Cache::get($this->itemCacheKey($key));
            PlatformCacheAudit::write(
                service: self::class,
                method: 'refreshItem',
                cacheKey: $this->itemCacheKey($key),
                oldPayload: is_array($oldItem) ? $oldItem : null,
                newPayload: $item,
            );
            Cache::put($this->itemCacheKey($key), $item, now()->addSeconds(self::CACHE_TTL_SECONDS));

            return $item;
        } catch (Throwable $exception) {
            report($exception);

            $previous = $this->cachedItem($key);

            $unavailable = $this->unavailableItem(
                $key,
                'Diagnostics failed. Showing last known summary when available.',
                $previous,
            );

            // Keep last successful overview card cached; only stamp unavailable view in response.
            return $unavailable;
        }
    }

    /**
     * Load diagnostics for a single integration. Never loads siblings.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(string $key): array
    {
        if (! $this->isKnownKey($key)) {
            abort(404);
        }

        return match ($key) {
            'radiumbox' => [
                'key' => 'radiumbox',
                'label' => 'RadiumBox',
                'partial' => 'admin.operations.partials.radiumbox-health',
                'health' => $this->radiumBoxHealth->widget(useCache: false),
            ],
            'cashfree' => [
                'key' => 'cashfree',
                'label' => 'Cashfree',
                'partial' => 'admin.operations.partials.cashfree-health',
                'health' => $this->cashfreeHealth->widget(useCache: false),
                'secondary_links' => [
                    [
                        'label' => 'Webhook Explorer',
                        'url' => route('cashfree.webhook-explorer.index'),
                    ],
                ],
            ],
            'gmail' => [
                'key' => 'gmail',
                'label' => 'Gmail',
                'partial' => 'admin.operations.partials.gmail-health',
                'health' => $this->gmailHealth->widget(),
                'show_actions' => true,
            ],
            'interakt' => [
                'key' => 'interakt',
                'label' => 'Interakt',
                'partial' => 'admin.platform.zones.integration-health.interakt-diagnostics',
                'card' => $this->refreshItem('interakt'),
                'templates' => $this->interaktTemplates->healthSummary(),
                'template_statuses' => $this->interaktTemplates->validateAll(),
            ],
            'zeptomail' => [
                'key' => 'zeptomail',
                'label' => 'ZeptoMail',
                'partial' => 'admin.platform.zones.integration-health.channel-diagnostics',
                'card' => $this->refreshItem('zeptomail'),
                'settings_url' => route('admin.system-settings.index'),
            ],
            'telegram' => [
                'key' => 'telegram',
                'label' => 'Telegram',
                'partial' => 'admin.platform.zones.integration-health.channel-diagnostics',
                'card' => $this->refreshItem('telegram'),
                'settings_url' => route('admin.system-settings.index'),
            ],
            'meta_flow' => [
                'key' => 'meta_flow',
                'label' => 'Meta',
                'partial' => 'admin.platform.zones.integration-health.channel-diagnostics',
                'card' => $this->refreshItem('meta_flow'),
                'settings_url' => null,
            ],
            default => abort(404),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     items: list<array<string, mixed>>,
     *     overall_status: string,
     *     overall_status_label: string,
     *     generated_at: string,
     *     available: bool
     * }
     */
    private function composeOverview(array $items, ?string $generatedAt, bool $available): array
    {
        $statuses = [];

        foreach ($items as $item) {
            $statuses[] = IntegrationHealthStatus::tryFrom((string) ($item['status'] ?? ''))
                ?? IntegrationHealthStatus::Unavailable;
        }

        $overall = $statuses === []
            ? IntegrationHealthStatus::Loading
            : IntegrationHealthStatus::worst(...$statuses);

        return [
            'items' => array_values($items),
            'overall_status' => $overall->value,
            'overall_status_label' => $overall->label(),
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
            'available' => $available,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadingItem(string $key): array
    {
        $status = IntegrationHealthStatus::Loading;

        return [
            'key' => $key,
            'label' => self::INTEGRATION_LABELS[$key] ?? $key,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'platform_status' => $status->toPlatform()->value,
            'platform_status_label' => $status->toPlatform()->label(),
            'summary' => 'Waiting for first refresh.',
            'detail' => 'Waiting for first refresh.',
            'updated_at' => null,
            'available' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function unavailableItem(string $key, string $message, ?array $previous = null): array
    {
        $status = IntegrationHealthStatus::Unavailable;

        return [
            'key' => $key,
            'label' => self::INTEGRATION_LABELS[$key] ?? ($previous['label'] ?? $key),
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'platform_status' => $status->toPlatform()->value,
            'platform_status_label' => $status->toPlatform()->label(),
            'summary' => $message,
            'detail' => $message,
            'updated_at' => $previous['updated_at'] ?? null,
            'last_successful_update' => $previous['updated_at'] ?? null,
            'available' => false,
            'retryable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function radiumBoxItem(): array
    {
        $widget = $this->radiumBoxHealth->widget(useCache: false);
        $enabled = (bool) ($widget['enabled'] ?? false);
        $failed = (int) ($widget['failed_syncs'] ?? 0);
        $pending = (int) ($widget['pending_syncs'] ?? 0);

        $opsStatus = match (true) {
            ! $enabled => OperationsHealthStatus::Disabled,
            $failed > 0 => OperationsHealthStatus::Failed,
            $pending > 10 => OperationsHealthStatus::Warning,
            default => OperationsHealthStatus::Healthy,
        };

        $detail = match ($opsStatus) {
            OperationsHealthStatus::Disabled => 'RadiumBox integration is disabled.',
            OperationsHealthStatus::Failed => sprintf('%d failed sync(s) need attention.', $failed),
            OperationsHealthStatus::Warning => sprintf('%d pending sync(s).', $pending),
            default => sprintf('Success rate %.1f%% (24h).', (float) ($widget['success_rate_24h'] ?? 100)),
        };

        return $this->item('radiumbox', 'RadiumBox', $opsStatus, $detail);
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreeItem(): array
    {
        $widget = $this->cashfreeHealth->widget(useCache: false);
        $opsStatus = ($widget['is_healthy'] ?? false)
            ? OperationsHealthStatus::Healthy
            : OperationsHealthStatus::Failed;

        return $this->item(
            'cashfree',
            'Cashfree',
            $opsStatus,
            (string) ($widget['detail'] ?? 'Payment webhook integration.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function gmailItem(): array
    {
        $card = $this->gmailHealth->card();
        $opsStatus = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''))
            ?? OperationsHealthStatus::NotConfigured;

        return $this->item(
            'gmail',
            'Gmail',
            $opsStatus,
            (string) ($card['detail'] ?? 'Gmail inbound sync.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function interaktItem(): array
    {
        if (! filled(Config::get('interakt.api_key'))) {
            $opsStatus = OperationsHealthStatus::NotConfigured;
            $detail = 'Interakt API key is not configured.';
        } elseif (! $this->systemSettings->getBool('whatsapp.api_enabled', false)) {
            $opsStatus = OperationsHealthStatus::Disabled;
            $detail = 'WhatsApp API integration is disabled.';
        } elseif (! Schema::hasTable('interakt_messages')) {
            $opsStatus = OperationsHealthStatus::Warning;
            $detail = 'Message store unavailable.';
        } else {
            $opsStatus = OperationsHealthStatus::Healthy;
            $detail = 'WhatsApp messaging is operational.';
        }

        $templates = $this->interaktTemplates->healthSummary();
        $templateStatus = $templates['status'] ?? null;

        if (
            $opsStatus === OperationsHealthStatus::Healthy
            && $templateStatus instanceof OperationsHealthStatus
            && $templateStatus !== OperationsHealthStatus::Healthy
        ) {
            $opsStatus = $templateStatus;
            $detail = (string) ($templates['detail'] ?? $detail);
        } elseif ($opsStatus === OperationsHealthStatus::Healthy) {
            $detail = sprintf(
                '%s · Templates %d/%d.',
                $detail,
                (int) ($templates['configured_count'] ?? 0),
                (int) ($templates['total_count'] ?? 0),
            );
        }

        return $this->item('interakt', 'Interakt', $opsStatus, $detail);
    }

    /**
     * @return array<string, mixed>
     */
    private function zeptomailItem(): array
    {
        return $this->channelConfigItem(
            key: 'zeptomail',
            label: 'ZeptoMail',
            enabledSetting: 'notifications.email.enabled',
            apiSetting: 'email.api_enabled',
            configured: (bool) config('mail.enabled') && config('mail.default') !== 'log',
            healthyDetail: 'Email notifications are enabled.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramItem(): array
    {
        return $this->channelConfigItem(
            key: 'telegram',
            label: 'Telegram',
            enabledSetting: 'notifications.telegram.enabled',
            apiSetting: 'telegram.api_enabled',
            configured: $this->systemSettings->getBool('telegram.api_enabled', false),
            healthyDetail: 'Telegram notifications are enabled.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFlowItem(): array
    {
        return $this->item(
            'meta_flow',
            'Meta',
            OperationsHealthStatus::NotConfigured,
            'Meta WhatsApp Flow integration is not yet configured.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function channelConfigItem(
        string $key,
        string $label,
        string $enabledSetting,
        string $apiSetting,
        bool $configured,
        string $healthyDetail,
    ): array {
        if (! $this->systemSettings->getBool($enabledSetting, false)) {
            return $this->item($key, $label, OperationsHealthStatus::Disabled, 'Channel is disabled in system settings.');
        }

        if (! $this->systemSettings->getBool($apiSetting, false)) {
            return $this->item($key, $label, OperationsHealthStatus::Disabled, 'API integration is disabled.');
        }

        if (! $configured) {
            return $this->item($key, $label, OperationsHealthStatus::NotConfigured, 'Integration credentials or transport are not configured.');
        }

        return $this->item($key, $label, OperationsHealthStatus::Healthy, $healthyDetail);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, string $label, OperationsHealthStatus $opsStatus, string $detail): array
    {
        $status = IntegrationHealthStatus::fromOperations($opsStatus);
        $platformStatus = $status->toPlatform();
        $updatedAt = now()->toIso8601String();

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'platform_status' => $platformStatus->value,
            'platform_status_label' => $platformStatus->label(),
            'summary' => $detail,
            'detail' => $detail,
            'updated_at' => $updatedAt,
            'available' => true,
            'retryable' => false,
        ];
    }

    private function maxTimestamp(?string $current, mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return $current;
        }

        try {
            $candidateAt = Carbon::parse($candidate);
        } catch (Throwable) {
            return $current;
        }

        if ($current === null || $current === '') {
            return $candidateAt->toIso8601String();
        }

        try {
            $currentAt = Carbon::parse($current);
        } catch (Throwable) {
            return $candidateAt->toIso8601String();
        }

        return ($candidateAt->greaterThan($currentAt) ? $candidateAt : $currentAt)->toIso8601String();
    }
}
