@php
    use App\Support\Settings\SettingsCenterNav;
    use App\Support\Settings\SettingsIcon;

    $activeKey = SettingsCenterNav::resolveActiveKey();
    $groups = SettingsCenterNav::groups($activeKey);
@endphp

<nav class="settings-center-sidebar" aria-label="Settings" data-settings-center-sidebar>
    <div class="settings-center-sidebar__search">
        {!! SettingsIcon::render('settings', 'settings-center-icon settings-center-icon--sm') !!}
        <input type="search"
               class="form-control form-control-sm"
               placeholder="Filter settings…"
               aria-label="Filter settings navigation"
               data-settings-center-nav-filter
               autocomplete="off">
    </div>

    <div class="settings-center-sidebar__groups">
        @foreach($groups as $group)
            <div class="settings-center-sidebar__group" data-settings-nav-group>
                <div class="settings-center-sidebar__group-label">{{ $group['label'] }}</div>
                <ul class="settings-center-sidebar__list">
                    @foreach($group['items'] as $item)
                        <li data-settings-nav-item data-settings-nav-label="{{ strtolower($item['label']) }}">
                            <a href="{{ $item['url'] }}"
                               @class([
                                   'settings-center-sidebar__link',
                                   'settings-center-sidebar__link--active' => $item['active'],
                               ])
                               @if($item['active']) aria-current="page" @endif>
                                {!! SettingsIcon::render($item['icon']) !!}
                                <span>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</nav>
