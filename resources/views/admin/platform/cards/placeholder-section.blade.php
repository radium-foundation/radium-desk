@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $upcoming = is_array($card->meta['upcoming_cards'] ?? null) ? $card->meta['upcoming_cards'] : [];
    $message = $card->meta['message'] ?? 'Cards coming next';
@endphp

<div class="platform-placeholder-section text-muted">
    <p class="small mb-2">{{ $message }}</p>
    @if($upcoming !== [])
        <ul class="small mb-0 ps-3">
            @foreach($upcoming as $upcomingCard)
                <li>{{ $upcomingCard }}</li>
            @endforeach
        </ul>
    @endif
</div>
