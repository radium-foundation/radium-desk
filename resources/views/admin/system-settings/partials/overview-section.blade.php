@php
    /** @var array{items: list<array<string, mixed>>, environment?: string, version?: string, build?: ?string, platform_url: string, platform_integrations_url: string, platform_tools_url?: string} $configurationHealth */
    $configurationHealth = $configurationHealth ?? [
        'items' => [],
        'environment' => config('app.env'),
        'version' => 'Unknown',
        'build' => null,
        'platform_url' => route('admin.platform.index'),
        'platform_integrations_url' => route('admin.platform.index').'#platform-zone-integration_health',
        'platform_tools_url' => route('admin.platform.index').'#platform-zone-tools',
    ];

    $lastChangeLabel = $lastUpdated
        ? $lastUpdated->timezone(config('app.timezone'))->diffForHumans()
        : 'No changes recorded';

    $environment = (string) ($configurationHealth['environment'] ?? config('app.env'));
    $version = (string) ($configurationHealth['version'] ?? 'Unknown');
    $build = $configurationHealth['build'] ?? null;
    $versionLabel = $build ? $version.' · '.$build : $version;
@endphp

<x-system-settings.section
    id="section-overview"
    icon="bi-grid-1x2"
    title="Configuration Overview"
    description="Configuration presence and environment summary. Live monitoring lives on Platform."
    :searchable="false"
>
    <div class="system-settings-overview-grid">
        @foreach($configurationHealth['items'] as $item)
            <x-system-settings.overview-widget
                :label="$item['label']"
                :value="$item['status_label']"
                :status="$item['status']"
                :hint="$item['hint']"
                :icon="match ($item['key']) {
                    'cashfree' => 'bi-credit-card',
                    'gmail' => 'bi-envelope',
                    'telegram' => 'bi-telegram',
                    'interakt' => 'bi-whatsapp',
                    'meta' => 'bi-meta',
                    'smtp' => 'bi-mailbox',
                    default => 'bi-plug',
                }"
            />
        @endforeach

        <x-system-settings.overview-widget
            label="Environment"
            :value="strtoupper($environment)"
            status="neutral"
            icon="bi-hdd-rack"
            hint="Application environment"
        />

        <x-system-settings.overview-widget
            label="Version / build"
            :value="$versionLabel"
            status="neutral"
            icon="bi-tag"
            hint="Deployed release metadata"
        />

        <x-system-settings.overview-widget
            label="Last configuration change"
            :value="$lastChangeLabel"
            status="neutral"
            icon="bi-clock-history"
            hint="Most recent System Settings update"
        />

        <div class="system-settings-widget system-settings-widget--neutral">
            <div class="system-settings-widget__icon" aria-hidden="true">
                <i class="bi bi-journal-text"></i>
            </div>
            <div class="system-settings-widget__body">
                <span class="system-settings-widget__label">Audit History</span>
                <span class="system-settings-widget__value">Setting changes</span>
                <span class="system-settings-widget__hint">
                    <button type="button"
                            class="btn btn-link btn-sm p-0 align-baseline"
                            data-system-settings-audit-open
                            aria-controls="system-settings-audit-drawer"
                            aria-expanded="false">
                        Open audit history
                    </button>
                </span>
            </div>
        </div>
    </div>

    <div class="mt-3 d-flex flex-wrap gap-2">
        <a href="{{ $configurationHealth['platform_url'] }}" class="btn btn-sm btn-outline-secondary">
            Open Platform monitoring
        </a>
        <a href="{{ $configurationHealth['platform_integrations_url'] }}" class="btn btn-sm btn-outline-secondary">
            Open Integration Health
        </a>
        <a href="{{ $configurationHealth['platform_tools_url'] ?? route('admin.platform.index').'#platform-zone-tools' }}" class="btn btn-sm btn-outline-secondary">
            Open Tools &amp; Diagnostics
        </a>
    </div>
</x-system-settings.section>
