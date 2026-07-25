@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $links = is_array($card->meta['workspace_links'] ?? null) ? $card->meta['workspace_links'] : [];
    $upcoming = is_array($card->meta['upcoming_cards'] ?? null) ? $card->meta['upcoming_cards'] : [];
    $message = $card->meta['message'] ?? 'Open the existing workspace for this area.';
@endphp

<div class="platform-placeholder-section">
    <p class="small text-muted mb-3">{{ $message }}</p>

    @if($links !== [])
        <div class="row g-2" data-platform-workspace-links>
            @foreach($links as $link)
                <div class="col-md-6 col-xl-4">
                    <a
                        href="{{ $link['url'] }}"
                        class="platform-workspace-link d-flex align-items-start gap-2 text-decoration-none border rounded-3 p-3 h-100"
                    >
                        <i class="bi bi-box-arrow-up-right text-primary mt-1" aria-hidden="true"></i>
                        <span>
                            <span class="d-block text-body fw-semibold">{{ $link['label'] }}</span>
                            @if(! empty($link['description']))
                                <span class="d-block text-muted small">{{ $link['description'] }}</span>
                            @endif
                        </span>
                    </a>
                </div>
            @endforeach
        </div>
    @elseif($upcoming !== [])
        <ul class="small text-muted mb-0 ps-3">
            @foreach($upcoming as $upcomingCard)
                <li>{{ $upcomingCard }}</li>
            @endforeach
        </ul>
    @endif
</div>
