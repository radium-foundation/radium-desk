@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $links = is_array($card->meta['workspace_links'] ?? null) ? $card->meta['workspace_links'] : [];
    $upcoming = is_array($card->meta['upcoming_cards'] ?? null) ? $card->meta['upcoming_cards'] : [];
@endphp

<div class="settings-center-platform-launchpad">
    @if($links !== [])
        <div class="settings-center-platform-launchpad__grid" data-platform-workspace-links>
            @foreach($links as $link)
                <a href="{{ $link['url'] }}" class="settings-center-platform-launchpad__tile">
                    <span class="settings-center-platform-launchpad__tile-label">{{ $link['label'] }}</span>
                </a>
            @endforeach
        </div>
    @elseif($upcoming !== [])
        <ul class="settings-center-platform-launchpad__upcoming">
            @foreach($upcoming as $upcomingCard)
                <li>{{ $upcomingCard }}</li>
            @endforeach
        </ul>
    @endif
</div>
