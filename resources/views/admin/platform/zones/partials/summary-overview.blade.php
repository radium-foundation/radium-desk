@props([
    'items' => [],
    'zoneKey' => '',
    'available' => false,
    'links' => [],
])

<div class="platform-summary-overview" data-platform-summary-overview="{{ $zoneKey }}">
    @if(is_array($items) && $items !== [])
        <div class="row g-3">
            @foreach($items as $item)
                @php
                    $key = (string) ($item['key'] ?? '');
                    $status = (string) ($item['status'] ?? 'loading');
                    $badge = (string) ($item['badge_class'] ?? 'secondary');
                    $expandable = (bool) ($item['expandable'] ?? false);
                    $expandedId = 'platform-summary-expand-'.$zoneKey.'-'.$key;
                    $expandUrl = route('admin.platform.zones.expand', [
                        'zone' => $zoneKey,
                        'item' => $key,
                    ]);
                @endphp
                <div class="col-sm-6 col-xl-4" data-platform-searchable="{{ strtolower(($item['label'] ?? '').' '.$zoneKey) }}">
                    <div
                        class="card border-0 shadow-sm h-100 platform-summary-overview__card"
                        data-platform-summary-card="{{ $key }}"
                        data-summary-status="{{ $status }}"
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
                                <span class="text-muted small">
                                    @if(! empty($item['updated_at']))
                                        Last Updated {{ \App\Support\AppDateFormatter::format(\Illuminate\Support\Carbon::parse($item['updated_at']), 'g:i A') }}
                                    @else
                                        Last Updated —
                                    @endif
                                </span>

                                @if($expandable)
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
                                @endif
                            </div>

                            @if($expandable)
                                <div
                                    id="{{ $expandedId }}"
                                    class="platform-summary-overview__expand mt-2 d-none"
                                    data-platform-integration-expand-panel
                                    hidden
                                ></div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-muted small mb-0">
            @if($available)
                No summary items available.
            @else
                Waiting for first background refresh.
            @endif
        </p>
    @endif

    @if(is_array($links) && $links !== [])
        <div class="d-flex flex-wrap gap-2 mt-3">
            @foreach($links as $link)
                <a href="{{ $link['url'] ?? '#' }}" class="btn btn-sm btn-outline-secondary">
                    {{ $link['label'] ?? 'Open' }}
                </a>
            @endforeach
        </div>
    @endif

    @if(! $available)
        <p class="text-muted small mt-3 mb-0">Showing cached or loading summaries. Live refresh runs after first paint.</p>
    @endif
</div>
