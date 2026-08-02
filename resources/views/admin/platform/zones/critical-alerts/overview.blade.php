@props([
    'alerts' => [],
    'zoneKey' => 'critical_alerts',
])

<div class="platform-critical-alerts" data-platform-critical-alerts>
    @if($alerts === [])
        <div class="text-muted small mb-0">No critical alerts. Cached zone snapshots are clear.</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($alerts as $alert)
                @php
                    /** @var \App\Data\Platform\PlatformAlert $alert */
                    $expandUrl = route('admin.platform.zones.expand', [
                        'zone' => $zoneKey,
                        'item' => $alert->groupKey,
                    ]);
                    $panelId = 'critical-alert-expand-'.$alert->groupKey;
                @endphp
                <div class="list-group-item px-0" data-platform-critical-alert="{{ $alert->groupKey }}">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge text-bg-{{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
                                <strong>{{ $alert->title }}</strong>
                                @if($alert->count > 1)
                                    <span class="text-muted small">{{ $alert->count }} related</span>
                                @endif
                            </div>
                            <p class="text-muted small mb-1">{{ $alert->summary }}</p>
                            <div class="text-muted small">
                                @if($alert->lastUpdated)
                                    Last Updated {{ \App\Support\AppDateFormatter::format($alert->lastUpdated, 'g:i A') }}
                                @else
                                    Last Updated —
                                @endif
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            @if($alert->link)
                                <a href="{{ $alert->link }}" class="btn btn-sm btn-outline-secondary">Open</a>
                            @endif
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                data-platform-integration-expand
                                data-expand-url="{{ $expandUrl }}"
                                data-expand-target="#{{ $panelId }}"
                                data-integration-key="{{ $alert->groupKey }}"
                                data-zone-key="{{ $zoneKey }}"
                                aria-expanded="false"
                                aria-controls="{{ $panelId }}"
                            >
                                Expand
                            </button>
                        </div>
                    </div>
                    <div id="{{ $panelId }}" class="mt-2 d-none" data-platform-integration-expand-panel hidden></div>
                </div>
            @endforeach
        </div>
    @endif
</div>
