@props([
    'active' => 'attendance',
])

@php
    $canPayroll = \App\Support\Workforce\AttendanceManagementAccess::allowsPayroll(auth()->user());

    $tabs = [
        'attendance' => [
            'label' => 'Attendance',
            'url' => route('workforce-management.attendance.index'),
            'enabled' => true,
            'visible' => true,
        ],
        'payroll' => [
            'label' => 'Payroll',
            'url' => route('workforce-management.payroll.index'),
            'enabled' => true,
            'visible' => $canPayroll,
        ],
        'salaries' => [
            'label' => 'Salaries',
            'url' => route('workforce-management.salaries.index'),
            'enabled' => true,
            'visible' => $canPayroll,
        ],
        'recognition' => [
            'label' => 'Work Recognition',
            'url' => config('workforce_recognition.enabled')
                ? route('workforce-management.recognition.index')
                : null,
            'enabled' => (bool) config('workforce_recognition.enabled')
                && auth()->user()?->can('workforce.recognition.view'),
            'visible' => true,
        ],
        'leave' => [
            'label' => 'Leave',
            'url' => null,
            'enabled' => false,
            'visible' => true,
        ],
        'calendar' => [
            'label' => 'Calendar',
            'url' => null,
            'enabled' => false,
            'visible' => true,
        ],
        'performance' => [
            'label' => 'Performance',
            'url' => null,
            'enabled' => false,
            'visible' => true,
        ],
    ];
@endphp

<nav class="workspace-nav wm-workspace-nav mb-0" aria-label="Workforce Management workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            @continue(! ($tab['visible'] ?? true))
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
