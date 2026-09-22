@php
    /** @var \App\Support\Navigation\NavigationContext $navigationContext */
    /** @var array<string, array{label: string, home_url: string, visible: bool, destination: array<string, mixed>}> $navigationSidebar */
@endphp

<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="brand d-flex align-items-center px-3">
        @include('layouts.partials.brand-mark')
    </div>

    <nav class="py-2">
        <ul class="nav flex-column app-sidebar-destinations">
            @foreach($navigationSidebar as $menu)
                @if($menu['visible'])
                    @php($destination = $menu['destination'])
                    <li class="nav-item">
                        <a @class(['nav-link', 'active' => $destination['active']])
                           href="{{ $destination['url'] }}"
                           title="{{ $destination['title'] }}"
                           data-nav-key="{{ $destination['key'] }}"
                           @if($destination['active']) aria-current="page" @endif>
                            <i class="bi {{ $destination['icon'] }} nav-icon me-2"></i>
                            <span class="nav-label">{{ $destination['label'] }}</span>
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    </nav>

    @include('layouts.partials.version-footer')
</aside>
