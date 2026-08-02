@props([
    'zone',
])

@php
    /** @var \App\Data\Platform\PlatformZoneViewData $zone */
    $definition = $zone->definition;
    $snapshot = $zone->snapshot;
@endphp

<section
    id="{{ $definition->domId() }}"
    class="settings-center-platform__section settings-center-platform__zone"
    data-platform-zone="{{ $definition->key() }}"
    data-platform-zone-priority="{{ $definition->refreshPriority }}"
    data-platform-section="{{ $definition->key() }}"
    data-platform-searchable="{{ strtolower($definition->title) }}"
    data-refresh-url="{{ route('admin.platform.zones.show', ['zone' => $definition->key()]) }}"
    @if($definition->expandable)
        data-expandable="true"
        data-expand-url="{{ route('admin.platform.zones.expand', ['zone' => $definition->key(), 'item' => 'default']) }}"
    @endif
    data-zone-status="{{ $snapshot->status->value }}"
    data-zone-available="{{ $snapshot->available ? 'true' : 'false' }}"
    data-zone-stale="{{ $snapshot->stale ? 'true' : 'false' }}"
>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi {{ $definition->icon }}" aria-hidden="true"></i>
            <div>
                <h2 class="h5 mb-0">{{ $definition->title }}</h2>
                @if($definition->description)
                    <p class="text-muted small mb-0">{{ $definition->description }}</p>
                @endif
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-{{ $snapshot->status->badgeClass() }}" data-platform-zone-status>
                {{ $snapshot->statusLabel }}
            </span>

            @if($snapshot->stale)
                <span class="badge text-bg-warning" data-platform-zone-stale title="Last known snapshot — background refresh pending">
                    Stale
                </span>
            @endif

            @if($snapshot->updatedAt)
                <span class="text-muted small" data-platform-zone-updated-at>
                    {{ \App\Support\AppDateFormatter::format($snapshot->updatedAt, 'g:i A') }}
                </span>
            @endif

            @if($definition->expandable)
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-platform-zone-expand
                    aria-expanded="false"
                    aria-controls="platform-zone-expand-{{ $definition->key() }}"
                >
                    Expand
                </button>
            @endif

            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-platform-zone-refresh
                title="Refresh zone"
                aria-label="Refresh {{ $definition->title }}"
            >
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div data-platform-zone-body>
        {!! $snapshot->html !!}
    </div>

    @if($definition->expandable)
        <div
            id="platform-zone-expand-{{ $definition->key() }}"
            class="mt-3 d-none"
            data-platform-zone-expand-panel
            hidden
        ></div>
    @endif
</section>
