@props([
    'items' => [],
])

<nav class="system-settings-sidebar"
     aria-label="Settings sections"
     data-system-settings-sidebar>
    <ul class="system-settings-sidebar__list">
        @foreach($items as $item)
            <li>
                <a href="#{{ $item['id'] }}"
                   class="system-settings-sidebar__link"
                   data-system-settings-nav-link="{{ $item['id'] }}">
                    <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
