@props([
    'teamAvailability' => ['on_duty' => [], 'unavailable' => []],
])

@php
    $onDutyMembers = $teamAvailability['on_duty'] ?? [];
    $unavailableMembers = $teamAvailability['unavailable'] ?? [];
@endphp

<section class="mb-4" aria-labelledby="team-presence-heading">
    <h2 id="team-presence-heading" class="h5 mb-3">Team Presence</h2>

    @if($onDutyMembers === [])
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-muted small mb-0">No team members are currently on duty.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm operations-team-card mb-4">
            <div class="card-header bg-white py-2">
                <h3 class="h6 mb-0">On duty</h3>
            </div>
            <div class="operations-team-header d-none d-md-grid">
                <span>Name</span>
                <span>Status</span>
                <span>Working time</span>
                <span>Workload</span>
                <span>Last activity</span>
                <span class="visually-hidden">Details</span>
            </div>

            <div class="list-group list-group-flush">
                @foreach($onDutyMembers as $member)
                    @include('admin.operations.partials.team-availability-row', [
                        'member' => $member,
                        'rowPrefix' => 'on-duty',
                    ])
                @endforeach
            </div>
        </div>
    @endif

    @if($unavailableMembers !== [])
        <div class="card border-0 shadow-sm operations-team-card border-warning-subtle">
            <div class="card-header bg-white py-2">
                <h3 class="h6 mb-0 text-warning-emphasis">Expected today but unavailable</h3>
            </div>
            <div class="operations-team-header d-none d-md-grid">
                <span>Name</span>
                <span>Status</span>
                <span>Working time</span>
                <span>Workload</span>
                <span>Last activity</span>
                <span class="visually-hidden">Details</span>
            </div>

            <div class="list-group list-group-flush">
                @foreach($unavailableMembers as $member)
                    @include('admin.operations.partials.team-availability-row', [
                        'member' => $member,
                        'rowPrefix' => 'unavailable',
                        'showUnavailability' => true,
                    ])
                @endforeach
            </div>
        </div>
    @endif
</section>
