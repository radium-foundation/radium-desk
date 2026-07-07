@php
    $displayValue = fn (?string $value) => filled($value) ? $value : 'Not Available';
    $hasSerial = filled($device['serial_number'] ?? null);
    $syncStatus = $device['serial_sync_status'] ?? \App\Enums\RadiumBoxEnrichmentSyncStatus::NotSynced->value;
    $isPending = ! $hasSerial && $syncStatus === \App\Enums\RadiumBoxEnrichmentSyncStatus::Pending->value;
    $isFailed = ! $hasSerial && $syncStatus === \App\Enums\RadiumBoxEnrichmentSyncStatus::Failed->value;
    $canManualSync = (bool) ($device['can_manual_sync'] ?? false);
    $showDiagnostics = (bool) ($device['show_sync_diagnostics'] ?? false);
@endphp

<section class="customer-360-section" data-customer-360-section="device" aria-labelledby="customer-360-device-heading">
    <h3 class="customer-360-section-title" id="customer-360-device-heading">Current Device</h3>
    <dl class="customer-360-dl">
        <div class="customer-360-dl-row">
            <dt>Model</dt>
            <dd>
                <span class="customer-360-device-model">{{ $displayValue($device['model_short'] ?? null) }}</span>
                @if(filled($device['model_canonical'] ?? null) && ($device['model_canonical'] ?? null) !== ($device['model_short'] ?? null))
                    <span class="customer-360-device-model-canonical">{{ $device['model_canonical'] }}</span>
                @elseif(filled($device['model_canonical'] ?? null) && blank($device['model_short'] ?? null))
                    <span class="customer-360-device-model-canonical">{{ $device['model_canonical'] }}</span>
                @endif
            </dd>
        </div>
        <div class="customer-360-dl-row customer-360-dl-row--serial">
            <dt class="customer-360-serial-label">
                <span>Serial Number</span>
                @if($canManualSync)
                    <button type="button"
                            class="customer-360-serial-sync-btn"
                            data-customer-360-radiumbox-sync
                            data-sync-url="{{ $device['manual_sync_url'] }}"
                            title="Retry RadiumBox synchronization"
                            aria-label="Retry RadiumBox synchronization"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top">
                        <x-heroicon.arrow-path />
                    </button>
                @endif
            </dt>
            <dd class="font-monospace">
                @if($hasSerial)
                    <x-customer-360-inline-copy
                        :value="$device['serial_number']"
                        label="Serial Number"
                        copy-key="serial"
                    />
                    @if($device['show_sync_freshness'] ?? false)
                        <div class="customer-360-sync-freshness"
                             @if(filled($device['last_synced_tooltip'] ?? null))
                                 title="{{ $device['last_synced_tooltip'] }}"
                                 data-bs-toggle="tooltip"
                                 data-bs-placement="top"
                             @endif>
                            <span class="customer-360-sync-freshness-icon" aria-hidden="true">✓</span>
                            <span class="customer-360-sync-freshness-label">Synced from RadiumBox</span>
                            @if(filled($device['last_synced_relative'] ?? null))
                                <span class="customer-360-sync-freshness-relative">{{ $device['last_synced_relative'] }}</span>
                            @elseif(filled($device['last_synced_label'] ?? null))
                                <span class="customer-360-sync-freshness-relative">{{ $device['last_synced_label'] }}</span>
                            @endif
                        </div>
                    @endif
                @elseif($isPending)
                    <span class="customer-360-serial-pending">⏳ Waiting for synchronization</span>
                @else
                    <span class="customer-360-serial-unavailable">Not Available</span>
                @endif
            </dd>
        </div>

        @if($showDiagnostics)
            <div class="customer-360-sync-diagnostics" data-customer-360-sync-diagnostics>
                <div class="customer-360-dl-row">
                    <dt>Sync Status</dt>
                    <dd>{{ $device['sync_status_label'] ?? 'Not Synced' }}</dd>
                </div>
                @if(filled($device['last_attempt_at'] ?? null))
                    <div class="customer-360-dl-row">
                        <dt>Last Attempt</dt>
                        <dd>{{ $device['last_attempt_at'] }}</dd>
                    </div>
                @endif
                @if($isFailed && filled($device['last_sync_error'] ?? null))
                    <div class="customer-360-dl-row">
                        <dt>Reason</dt>
                        <dd class="customer-360-sync-reason">{{ $device['last_sync_error'] }}</dd>
                    </div>
                @endif
            </div>
        @endif

        <div class="customer-360-dl-row">
            <dt>Order ID</dt>
            <dd>
                <x-customer-360-inline-copy
                    :value="$device['order_id'] ?? null"
                    label="Order ID"
                    copy-key="order-id"
                >
                    @if($device['is_legacy_imported'] ?? false)
                        <span class="legacy-imported-order-indicator"
                              title="{{ $device['legacy_import_tooltip'] }}"
                              aria-label="{{ $device['legacy_import_tooltip'] }}">↓</span>
                    @endif
                    {{ $device['order_id'] }}
                </x-customer-360-inline-copy>
            </dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Service Reference</dt>
            <dd>{{ $displayValue($device['service_reference'] ?? null) }}</dd>
        </div>
    </dl>
</section>
