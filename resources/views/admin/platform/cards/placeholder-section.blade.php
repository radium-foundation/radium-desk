@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $links = is_array($card->meta['workspace_links'] ?? null) ? $card->meta['workspace_links'] : [];
    $upcoming = is_array($card->meta['upcoming_cards'] ?? null) ? $card->meta['upcoming_cards'] : [];
    $message = $card->meta['message'] ?? 'Open the existing workspace for this area.';
@endphp

<div class="settings-center-platform-placeholder">
    <p class="settings-center-platform-placeholder__message">{{ $message }}</p>

    @if($links !== [])
        <div class="settings-center-platform-links" data-platform-workspace-links>
            @foreach($links as $link)
                <a href="{{ $link['url'] }}"
                   class="settings-center-platform-link">
                    <span class="settings-center-platform-link__icon" aria-hidden="true">
                        <x-settings-center.icon name="external-link" class="settings-center-icon settings-center-icon--sm" />
                    </span>
                    <span class="settings-center-platform-link__content">
                        <span class="settings-center-platform-link__label">{{ $link['label'] }}</span>
                        @if(! empty($link['description']))
                            <span class="settings-center-platform-link__description">{{ $link['description'] }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @elseif($upcoming !== [])
        <ul class="settings-center-platform-placeholder__list">
            @foreach($upcoming as $upcomingCard)
                <li>{{ $upcomingCard }}</li>
            @endforeach
        </ul>
    @endif
</div>
