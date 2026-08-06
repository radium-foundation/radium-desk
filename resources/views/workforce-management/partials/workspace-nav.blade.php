@props([
    'active' => 'attendance',
])

@php
    $viewer = auth()->user();
    $canPayroll = \App\Support\Workforce\AttendanceManagementAccess::allowsPayroll($viewer);
    $canLeave = $viewer?->can('viewAny', \App\Models\LeaveRequest::class) ?? false;
    $recognitionEnabled = (bool) config('workforce_recognition.enabled')
        && ($viewer?->can('workforce.recognition.view') ?? false);
    $canShortAttendanceReview = app(\App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService::class)
        ->canView($viewer);

    $tabs = [
        'attendance' => [
            'label' => 'Attendance',
            'url' => route('workforce-management.attendance.index'),
            'enabled' => true,
            'visible' => true,
        ],
        'short-attendance' => [
            'label' => 'Short Attendance Review',
            'url' => route('workforce-management.short-attendance.index', ['period' => 'today', 'status' => 'pending']),
            'enabled' => true,
            'visible' => $canShortAttendanceReview,
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
            'url' => route('workforce-management.recognition.index'),
            'enabled' => true,
            'visible' => $recognitionEnabled,
        ],
        'leave' => [
            'label' => 'Leave',
            'url' => route('leave-requests.index'),
            'enabled' => true,
            'visible' => $canLeave,
        ],
    ];
@endphp

<nav class="workspace-nav wm-workspace-nav mb-0" aria-label="Workforce Management workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            @continue(! ($tab['visible'] ?? true))
            <li class="nav-item" role="presentation">
                <a
                    @class(['nav-link', 'active' => $active === $key])
                    href="{{ $tab['url'] }}"
                    @if($active === $key) aria-current="page" @endif
                >
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
