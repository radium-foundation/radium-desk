@props([
    'id' => null,
    'icon' => null,
    'title',
    'description' => null,
    'searchable' => true,
])

<section
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => 'settings-center-section']) }}
    aria-labelledby="{{ $id ? $id.'-title' : null }}"
    @if($searchable) data-settings-center-section data-platform-section-anchor @endif
>
    <header class="settings-center-section__header">
        @if($icon)
            <span class="settings-center-section__icon" aria-hidden="true">
                <i class="bi {{ $icon }}"></i>
            </span>
        @endif
        <div class="settings-center-section__heading">
            <h2 class="settings-center-section__title" @if($id) id="{{ $id }}-title" @endif>{{ $title }}</h2>
            @if(filled($description))
                <p class="settings-center-section__description">{{ $description }}</p>
            @endif
        </div>
    </header>

    <div class="settings-center-section__body">
        {{ $slot }}
    </div>
</section>
