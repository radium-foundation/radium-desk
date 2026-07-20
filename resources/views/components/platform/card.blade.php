@props([
    'card',
])

@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $refreshUrl = route('admin.platform.cards.show', ['card' => $card->key]);
    $updatedLabel = \App\Support\AppDateFormatter::format($card->generatedAt, 'H:i');
@endphp

<article
    class="card border-0 shadow-sm h-100 platform-dashboard-card"
    data-platform-card
    data-card-key="{{ $card->key }}"
    @if($card->refreshable)
        data-refresh-url="{{ $refreshUrl }}"
    @endif
>
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h3 class="h6 mb-0">{{ $card->title }}</h3>
                <x-platform.status-badge :status="$card->status" />
            </div>
            <div class="text-muted small mt-1" data-platform-card-updated>
                Updated {{ $updatedLabel }}
            </div>
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
            @if($card->refreshable)
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-platform-card-refresh
                    title="Refresh card"
                    aria-label="Refresh {{ $card->title }}"
                >
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                </button>
            @endif
            @if($card->actions !== [])
                <div class="dropdown">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="More actions"
                    >
                        <i class="bi bi-three-dots" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @foreach($card->actions as $action)
                            <li>
                                <a class="dropdown-item" href="{{ $action['url'] ?? '#' }}">
                                    {{ $action['label'] ?? 'Action' }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <div class="card-body">
        @if(filled($card->bodyPartial))
            @include($card->bodyPartial, ['card' => $card])
        @else
            @foreach($card->metrics as $metric)
                <x-platform.metric-row :metric="$metric" />
            @endforeach
        @endif
    </div>

    @if(filled($card->detailUrl))
        <div class="card-footer bg-white border-top">
            <a href="{{ $card->detailUrl }}" class="small text-decoration-none">
                View Details
                <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
            </a>
        </div>
    @endif
</article>
