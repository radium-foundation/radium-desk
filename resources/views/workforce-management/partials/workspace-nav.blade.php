@props([
    'active' => 'attendance',
])

@php
    $tabs = [
        'attendance' => [
            'label' => 'Attendance',
            'url' => route('workforce-management.attendance.index'),
            'enabled' => true,
        ],
        'recognition' => [
            'label' => 'Work Recognition',
            'url' => config('workforce_recognition.enabled')
                ? route('workforce-management.recognition.index')
                : null,
            'enabled' => (bool) config('workforce_recognition.enabled')
                && auth()->user()?->can('workforce.recognition.view'),
        ],
        'leave' => [
            'label' => 'Leave',
            'url' => null,
            'enabled' => false,
        ],
        'calendar' => [
            'label' => 'Calendar',
            'url' => null,
            'enabled' => false,
        ],
        'performance' => [
            'label' => 'Performance',
            'url' => null,
            'enabled' => false,
        ],
    ];
@endphp

<nav class="workspace-nav wm-workspace-nav mb-0" aria-label="Workforce Management workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            <li class="nav-item" role="presentation">
                @if($tab['enabled'])
                    <a
                        @class(['nav-link', 'active' => $active === $key])
                        href="{{ $tab['url'] }}"
                        @if($active === $key) aria-current="page" @endif
                    >
                        {{ $tab['label'] }}
                    </a>
                @else
                    <span
                        @class(['nav-link', 'disabled', 'text-muted'])
                        aria-disabled="true"
                        title="Coming soon"
                    >
                        {{ $tab['label'] }}
                        <span class="wm-soon-pill">Soon</span>
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
