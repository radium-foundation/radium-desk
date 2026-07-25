@props([
    'sections' => [],
])

@php
    use App\Support\Settings\SettingsIcon;
@endphp

<nav class="settings-center-sidebar" aria-label="Platform sections" data-platform-sidebar>
    <div class="settings-center-sidebar__search">
        {!! SettingsIcon::render('layout-dashboard', 'settings-center-icon settings-center-icon--sm') !!}
        <input type="search"
               class="form-control form-control-sm"
               placeholder="Filter sections…"
               aria-label="Filter platform sections"
               data-platform-sidebar-filter
               autocomplete="off">
    </div>

    <div class="settings-center-sidebar__groups">
        <div class="settings-center-sidebar__group" data-platform-sidebar-group>
            <div class="settings-center-sidebar__group-label">Mission Control</div>
            <ul class="settings-center-sidebar__list">
                @foreach($sections as $section)
                    @php
                        $sectionKey = $section['key'] ?? '';
                        $sectionId = $sectionKey === 'platform_health' ? 'platform-health' : 'platform-section-'.$sectionKey;
                    @endphp
                    <li data-platform-sidebar-item data-platform-sidebar-label="{{ strtolower($section['label'] ?? '') }}">
                        <a href="#{{ $sectionId }}"
                           class="settings-center-sidebar__link"
                           data-platform-sidebar-link>
                            @if(! empty($section['icon']))
                                <i class="bi {{ $section['icon'] }} settings-center-sidebar__link-icon" aria-hidden="true"></i>
                            @endif
                            <span>{{ $section['label'] ?? '' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</nav>
