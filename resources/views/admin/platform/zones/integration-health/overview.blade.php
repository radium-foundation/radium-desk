@props([
    'items' => [],
    'zoneKey' => 'integration_health',
    'available' => false,
])

<div class="platform-integration-health" data-platform-integration-health>
    <div class="row g-3">
        @foreach($items as $item)
            @php
                $key = (string) ($item['key'] ?? '');
                $status = (string) ($item['status'] ?? 'loading');
                $badge = (string) ($item['badge_class'] ?? 'secondary');
                $expandedId = 'platform-integration-expand-'.$key;
                $expandUrl = route('admin.platform.zones.expand', [
                    'zone' => $zoneKey,
                    'item' => $key,
                ]);
            @endphp
            <div class="col-sm-6 col-xl-4" data-platform-searchable="{{ strtolower(($item['label'] ?? '').' integration') }}">
                <div
                    class="card border-0 shadow-sm h-100 platform-integration-health__card"
                    data-platform-integration-card="{{ $key }}"
                    data-integration-status="{{ $status }}"
                >
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h3 class="h6 mb-0">{{ $item['label'] ?? $key }}</h3>
                            <span class="badge text-bg-{{ $badge }}">{{ $item['status_label'] ?? 'Unknown' }}</span>
                        </div>

                        <p class="text-muted small mb-0 flex-grow-1">
                            {{ $item['summary'] ?? ($item['detail'] ?? 'No summary available.') }}
                        </p>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-1">
                            <span class="text-muted small" data-integration-updated-at>
                                @if(! empty($item['updated_at']))
                                    Last Updated {{ \App\Support\AppDateFormatter::format(\Illuminate\Support\Carbon::parse($item['updated_at']), 'g:i A') }}
                                @elseif(! empty($item['last_successful_update']))
                                    Last successful {{ \App\Support\AppDateFormatter::format(\Illuminate\Support\Carbon::parse($item['last_successful_update']), 'g:i A') }}
                                @else
                                    Last Updated —
                                @endif
                            </span>

                            <div class="d-flex gap-2">
                                @if(! empty($item['retryable']))
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-platform-zone-refresh
                                        title="Retry zone refresh"
                                    >
                                        Retry
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-platform-integration-expand
                                    data-expand-url="{{ $expandUrl }}"
                                    data-expand-target="#{{ $expandedId }}"
                                    data-integration-key="{{ $key }}"
                                    data-zone-key="{{ $zoneKey }}"
                                    aria-expanded="false"
                                    aria-controls="{{ $expandedId }}"
                                >
                                    Expand
                                </button>
                            </div>
                        </div>

                        <div
                            id="{{ $expandedId }}"
                            class="platform-integration-health__expand mt-2 d-none"
                            data-platform-integration-expand-panel
                            hidden
                        ></div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if(! $available)
        <p class="text-muted small mt-3 mb-0">Showing cached or loading summaries. Live refresh runs after first paint.</p>
    @endif
</div>
