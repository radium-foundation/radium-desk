@php
    $performanceProfile = (string) ($performanceHealth['performance_profile'] ?? $performanceProfile ?? 'balanced');
@endphp

<x-system-settings.section
    id="category-system"
    icon="bi-info-circle"
    title="Environment"
    description="Configured values for this deployment. Live runtime diagnostics are on Platform."
>
    <x-system-settings.card
        title="Environment & configuration snapshot"
        description="Read-only configured values — not live production health."
        class="mb-3"
    >
        <div class="system-settings-details-grid">
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Environment</span>
                <span class="system-settings-detail__value"><code>{{ config('app.env') }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Performance profile</span>
                <span class="system-settings-detail__value">{{ ucwords(str_replace('_', ' ', $performanceProfile)) }}</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Dashboard polling (configured)</span>
                <span class="system-settings-detail__value">{{ number_format($performanceHealth['dashboard_poll_interval_ms'] ?? 0) }} ms</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Notification polling (configured)</span>
                <span class="system-settings-detail__value">{{ number_format($performanceHealth['notification_poll_interval_ms'] ?? 0) }} ms</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Broadcast driver</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['broadcast_driver'] ?? config('broadcasting.default') }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Dashboard live mode (configured)</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['dashboard_live_mode'] ?? '—' }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Realtime provider (configured)</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['realtime_provider'] ?? 'polling' }}</code></span>
            </div>
        </div>

        <details class="system-settings-health-details mt-3">
            <summary>Hybrid Realtime feature flags</summary>
            <ul class="system-settings-health-features">
                @foreach(($performanceHealth['hybrid_realtime_features'] ?? []) as $feature)
                    <li>
                        <span>{{ $feature['label'] }}</span>
                        @if(! ($feature['wired'] ?? false))
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--neutral">Not wired</span>
                        @elseif($feature['enabled'] ?? false)
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--success">On</span>
                        @else
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--neutral">Off</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    </x-system-settings.card>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-outline-secondary">
            Open Platform monitoring
        </a>
        <a href="{{ route('admin.platform.index') }}#platform-zone-tools" class="btn btn-sm btn-outline-secondary">
            Open Tools &amp; Diagnostics
        </a>
        <a href="#section-advanced" class="btn btn-sm btn-outline-secondary">
            Edit Advanced settings
        </a>
    </div>
</x-system-settings.section>
