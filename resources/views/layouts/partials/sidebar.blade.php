@php
    /** @var \App\Support\Navigation\NavigationContext $navigationContext */
    /** @var array<string, array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>}> $navigationSidebar */
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="brand d-flex align-items-center px-3">
        @include('layouts.partials.brand-mark')
    </div>

    <nav class="py-2">
        @foreach($navigationSidebar as $menuKey => $menu)
            @if($menu['visible'])
                <div class="nav-section">
                    <a href="{{ $menu['home_url'] }}" class="nav-section-link text-decoration-none" title="{{ $menu['label'] }} home">
                        <span class="nav-label">{{ $menu['label'] }}</span>
                    </a>
                </div>
                <ul class="nav flex-column">
                    @foreach($menu['items'] as $item)
                        <li class="nav-item">
                            <a @class(['nav-link', 'active' => $item['active']])
                               href="{{ $item['url'] }}"
                               title="{{ $item['title'] }}"
                               data-nav-key="{{ $item['key'] }}"
                               @if(! empty($item['open_todo_modal']))
                                   data-todo-modal-open
                                   data-todo-url="{{ $item['url'] }}"
                               @endif
                               @if($item['active']) aria-current="page" @endif>
                                <i class="bi {{ $item['icon'] }} nav-icon me-2"></i>
                                <span class="nav-label">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </nav>

    @include('layouts.partials.version-footer')
</aside>
