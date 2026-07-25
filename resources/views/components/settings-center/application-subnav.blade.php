@props([
    'tabs' => [],
    'active' => 'general',
])

<nav class="settings-center-subnav" aria-label="Application sections">
    <ul class="settings-center-subnav__list">
        @foreach($tabs as $key => $label)
            <li>
                <a href="{{ route('settings.index', ['tab' => $key]) }}"
                   @class([
                       'settings-center-subnav__link',
                       'settings-center-subnav__link--active' => $active === $key,
                   ])
                   @if($active === $key) aria-current="page" @endif>
                    {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
