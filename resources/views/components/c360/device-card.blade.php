@props([
    'device' => [],
    'activeServices' => [],
])

@php
    $hasSerial = filled($device['serial_number'] ?? null);
    $syncStatus = $device['serial_sync_status'] ?? \App\Enums\RadiumBoxEnrichmentSyncStatus::NotSynced->value;
    $isPending = ! $hasSerial && $syncStatus === \App\Enums\RadiumBoxEnrichmentSyncStatus::Pending->value;
    $isFailed = ! $hasSerial && $syncStatus === \App\Enums\RadiumBoxEnrichmentSyncStatus::Failed->value;
    $canManualSync = (bool) ($device['can_manual_sync'] ?? false);
    $showDiagnostics = (bool) ($device['show_sync_diagnostics'] ?? false);
    $warranty = collect($activeServices)->firstWhere('label', 'Warranty')['status'] ?? null;
@endphp

<section {{ $attributes->merge(['class' => 'c360-device-card']) }}
         data-customer-360-section="device"
         aria-labelledby="c360-device-card-heading">
    <div class="c360-device-card-header">
        <div class="c360-device-card-icon" aria-hidden="true">
            <i class="bi bi-hdd-stack"></i>
        </div>
        <div class="c360-device-card-title-wrap">
            <h3 class="c360-device-card-heading" id="c360-device-card-heading">Current Device</h3>
            <p class="c360-device-card-model mb-0">
                @if(filled($device['model_short'] ?? null))
                    {{ $device['model_short'] }}
                @elseif(filled($device['model_canonical'] ?? null))
                    {{ $device['model_canonical'] }}
                @else
                    <x-c360.unavailable-pill />
                @endif
            </p>
            @if(filled($device['model_canonical'] ?? null) && ($device['model_canonical'] ?? null) !== ($device['model_short'] ?? null) && filled($device['model_short'] ?? null))
                <p class="c360-device-card-model-canonical mb-0">{{ $device['model_canonical'] }}</p>
            @endif
        </div>
    </div>

    <div class="c360-device-card-chips">
        <div class="c360-device-card-chip-row">
            <span class="c360-device-card-chip-label">Serial</span>
            <span class="c360-device-card-chip-value">
                @if($hasSerial)
                    <x-customer-360-inline-copy
                        :value="$device['serial_number']"
                        label="Serial Number"
                        copy-key="serial"
                    />
                @elseif($isPending)
                    <x-c360.status-banner variant="waiting" icon="⏳">Syncing</x-c360.status-banner>
                @elseif($isFailed)
                    <x-c360.status-banner variant="danger" icon="✖">Sync failed</x-c360.status-banner>
                @else
                    <x-c360.unavailable-pill />
                @endif
                @if($canManualSync)
                    <button type="button"
                            class="c360-device-card-sync-btn"
                            data-customer-360-radiumbox-sync
                            data-sync-url="{{ $device['manual_sync_url'] }}"
                            title="Retry RadiumBox synchronization"
                            aria-label="Retry RadiumBox synchronization">
                        <x-heroicon.arrow-path />
                    </button>
                @endif
            </span>
        </div>

        @if($hasSerial && ($device['show_sync_freshness'] ?? false))
            <x-c360.chip
                value="{{ $device['last_synced_relative'] ?? $device['last_synced_label'] ?? 'Synced' }}"
                variant="success"
                icon="bi-check-circle"
                class="c360-chip--compact"
            />
        @endif

        <div class="c360-device-card-chip-row">
            <span class="c360-device-card-chip-label">{{ ($device['is_inquiry'] ?? false) ? 'Case' : 'Order' }}</span>
            <span class="c360-device-card-chip-value font-monospace">
                @if($device['is_inquiry'] ?? false)
                    @if(filled($device['case_reference'] ?? null))
                        <x-customer-360-inline-copy
                            :value="$device['case_reference']"
                            label="Case"
                            copy-key="case-reference"
                        />
                    @else
                        <x-c360.unavailable-pill />
                    @endif
                @elseif(filled($device['order_id'] ?? null))
                    <x-customer-360-inline-copy
                        :value="$device['order_id']"
                        label="Order ID"
                        copy-key="order-id"
                    />
                @else
                    <x-c360.unavailable-pill />
                @endif
            </span>
        </div>

        @if(filled($warranty))
            <x-c360.chip :value="'Warranty: ' . $warranty" variant="neutral" class="c360-chip--compact" />
        @endif

        @if(filled($device['service_reference'] ?? null))
            <x-c360.chip :value="'Service: ' . $device['service_reference']" variant="info" class="c360-chip--compact" />
        @endif

        @if($device['is_inquiry'] ?? false)
            <x-c360.chip value="Enquiry" variant="neutral" class="c360-chip--compact" />
        @endif
    </div>

    @if($showDiagnostics)
        <details class="c360-device-card-diagnostics" data-customer-360-sync-diagnostics>
            <summary class="c360-device-card-diagnostics-summary">Sync diagnostics</summary>
            <dl class="c360-device-card-diagnostics-grid">
                <div>
                    <dt>Status</dt>
                    <dd>{{ $device['sync_status_label'] ?? 'Not Synced' }}</dd>
                </div>
                @if(filled($device['last_attempt_at'] ?? null))
                    <div>
                        <dt>Last attempt</dt>
                        <dd>{{ $device['last_attempt_at'] }}</dd>
                    </div>
                @endif
                @if($isFailed && filled($device['last_sync_error'] ?? null))
                    <div>
                        <dt>Reason</dt>
                        <dd>{{ $device['last_sync_error'] }}</dd>
                    </div>
                @endif
            </dl>
        </details>
    @endif
</section>
