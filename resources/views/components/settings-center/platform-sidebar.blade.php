@props([
    'zones' => [],
    'sections' => [],
])

@php
    use App\Support\Settings\SettingsIcon;

    $sidebarItems = $zones !== []
        ? collect($zones)->map(fn ($zone) => [
            'key' => $zone->definition->key(),
            'label' => $zone->definition->title,
            'icon' => $zone->definition->icon,
            'dom_id' => $zone->definition->domId(),
        ])->all()
        : collect($sections)->map(function (array $section) {
            $sectionKey = $section['key'] ?? '';

            return [
                'key' => $sectionKey,
                'label' => $section['label'] ?? '',
                'icon' => $section['icon'] ?? null,
                'dom_id' => $sectionKey === 'platform_health' ? 'platform-health' : 'platform-section-'.$sectionKey,
            ];
        })->all();
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
                @foreach($sidebarItems as $item)
                    <li data-platform-sidebar-item data-platform-sidebar-label="{{ strtolower($item['label'] ?? '') }}">
                        <a href="#{{ $item['dom_id'] }}"
                           class="settings-center-sidebar__link"
                           data-platform-sidebar-link>
                            @if(! empty($item['icon']))
                                <i class="bi {{ $item['icon'] }} settings-center-sidebar__link-icon" aria-hidden="true"></i>
                            @endif
                            <span>{{ $item['label'] ?? '' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</nav>
