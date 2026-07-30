@php
    /** @var \App\Data\Workforce\WorkforceMember360Profile $profile */
@endphp

<div class="wm360-content" data-wm360-content data-focused-day="{{ $profile->focusedDay }}">
    @include('workforce-management.member-360.partials.header', ['header' => $profile->header, 'monthLabel' => $profile->attendance->monthLabel])

    <nav class="wm360-tabs" aria-label="Workforce Member 360 sections">
        @foreach($profile->tabs as $tab)
            @if($tab['enabled'])
                <button
                    type="button"
                    class="wm360-tabs__item is-active"
                    data-wm360-tab="{{ $tab['key'] }}"
                    aria-current="page"
                >
                    {{ $tab['label'] }}
                </button>
            @else
                <span class="wm360-tabs__item is-disabled" aria-disabled="true" title="Coming soon">
                    {{ $tab['label'] }}
                    <span class="wm360-soon">Soon</span>
                </span>
            @endif
        @endforeach
    </nav>

    <div class="wm360-panels">
        <section class="wm360-panel" data-wm360-panel="overview">
            @include('workforce-management.member-360.partials.overview', ['profile' => $profile])
        </section>
    </div>
</div>
