@props([
    'sections' => [],
])

@php
    $launchpadSearchTerms = collect($sections)
        ->flatMap(fn (array $section): array => [
            $section['label'] ?? '',
            $section['key'] ?? '',
        ])
        ->filter()
        ->implode(' ');
@endphp

<div
    class="settings-center-platform__launchpads settings-center-platform__section"
    id="platform-launchpads"
    data-platform-section="launchpads"
    data-platform-searchable="{{ strtolower($launchpadSearchTerms.' operations finance queue orders cases customers settings workforce automation communications system') }}"
>
    <div class="row g-3 settings-center-platform__launchpad-grid">
        @foreach($sections as $section)
            @php
                $sectionKey = $section['key'] ?? '';
                $sectionId = 'platform-section-'.$sectionKey;
            @endphp
            @foreach($section['cards'] ?? [] as $card)
                <div
                    id="{{ $sectionId }}"
                    class="col-12 col-md-6 col-xl-4 settings-center-platform__card-slot settings-center-platform__launchpad-slot"
                    data-platform-card-slot="{{ $card->key }}"
                    data-platform-searchable="{{ strtolower($card->title.' '.($section['label'] ?? '').' '.collect($card->meta['workspace_links'] ?? [])->pluck('label')->implode(' ')) }}"
                >
                    <x-platform.card :card="$card" />
                </div>
            @endforeach
        @endforeach
    </div>
</div>
