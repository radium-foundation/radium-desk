@props([
    'intelligence' => [],
])

@php
    $today = $intelligence['today'] ?? [];
    $upcoming = $intelligence['upcoming'] ?? [];
    $customerResponse = $intelligence['customer_response'] ?? [];
    $teamWorkload = $intelligence['team_workload'] ?? [];

    $scheduledToday = (int) ($today['scheduled'] ?? 0);
    $completedToday = (int) ($today['completed'] ?? 0);
    $pendingToday = (int) ($today['pending'] ?? 0);
    $missedOverdue = (int) ($today['missed_overdue'] ?? 0);
    $tomorrow = (int) ($upcoming['tomorrow'] ?? 0);
    $nextSevenDays = (int) ($upcoming['next_seven_days'] ?? 0);
    $serialRequested = (int) ($customerResponse['serial_requested'] ?? 0);
    $serialReceived = (int) ($customerResponse['serial_received'] ?? 0);
    $stillWaiting = (int) ($customerResponse['still_waiting'] ?? 0);
    $showDetails = $missedOverdue > 0 || $stillWaiting > 0;

    $workloadCards = collect($teamWorkload)
        ->map(function (array $member): array {
            $todayCount = (int) ($member['today'] ?? 0);
            $pendingCount = (int) ($member['pending'] ?? 0);
            $activeCases = (int) ($member['active_cases'] ?? 0);
            $totalLoad = $todayCount + $pendingCount;
            $loadPercent = min(100, (int) round(($totalLoad / max(1, 8)) * 100));
            $capacityClass = match (true) {
                $loadPercent >= 91 => 'danger',
                $loadPercent >= 71 => 'warning',
                default => 'healthy',
            };
            $progressTone = $capacityClass === 'healthy' ? 'success' : $capacityClass;

            return [
                'name' => $member['name'] ?? 'Unknown',
                'today' => $todayCount,
                'pending' => $pendingCount,
                'active_cases' => $activeCases,
                'load_percent' => $loadPercent,
                'capacity_class' => $capacityClass,
                'progress_tone' => $progressTone,
            ];
        })
        ->filter(fn (array $member): bool => $member['today'] > 0
            || $member['pending'] > 0
            || $member['active_cases'] > 0)
        ->values()
        ->all();
@endphp

<section class="mb-0" aria-labelledby="support-intelligence-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h3 id="support-intelligence-heading" class="h6 mb-0">Support Intelligence</h3>
        <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#operations-support-intelligence-details"
            data-operations-view-all-label="Show details"
            data-operations-view-less-label="Hide details"
            aria-expanded="{{ $showDetails ? 'true' : 'false' }}"
            aria-controls="operations-support-intelligence-details"
        >
            {{ $showDetails ? 'Hide details' : 'Show details' }}
        </button>
    </div>

    <div class="operations-support-intelligence-summary card border-0 shadow-sm mb-2 operations-card-hover">
        <div class="card-body py-2 px-3">
            <div class="row g-2 small">
                <div class="col-6 col-md-3">
                    <span class="text-muted">Today</span>
                    <strong class="d-block">{{ number_format($scheduledToday) }} scheduled · {{ number_format($pendingToday) }} pending</strong>
                </div>
                <div class="col-6 col-md-3">
                    <span class="text-muted">Upcoming</span>
                    <strong class="d-block">{{ number_format($tomorrow) }} tomorrow · {{ number_format($nextSevenDays) }} next 7 days</strong>
                </div>
                <div class="col-6 col-md-3">
                    <span class="text-muted">Customer response</span>
                    <strong class="d-block">{{ number_format($stillWaiting) }} still waiting</strong>
                </div>
                <div class="col-6 col-md-3">
                    <span class="text-muted">Overdue</span>
                    <strong @class(['d-block', 'text-danger' => $missedOverdue > 0])>{{ number_format($missedOverdue) }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div id="operations-support-intelligence-details" @class(['collapse', 'show' => $showDetails])>
        <div class="card border-0 shadow-sm operations-card-hover">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <p class="text-uppercase text-muted small fw-semibold mb-2">Today's Support</p>
                        <dl class="row mb-0 small">
                            <dt class="col-7">Scheduled</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($scheduledToday) }}</dd>
                            <dt class="col-7">Completed</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($completedToday) }}</dd>
                            <dt class="col-7">Pending</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($pendingToday) }}</dd>
                            @if ($missedOverdue > 0)
                                <dt class="col-7">Missed / overdue</dt>
                                <dd class="col-5 text-end mb-0 text-danger fw-semibold">{{ number_format($missedOverdue) }}</dd>
                            @endif
                        </dl>
                    </div>

                    <div class="col-md-4">
                        <p class="text-uppercase text-muted small fw-semibold mb-2">Upcoming Support</p>
                        <dl class="row mb-0 small">
                            <dt class="col-7">Tomorrow</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($tomorrow) }}</dd>
                            <dt class="col-7">Next 7 days</dt>
                            <dd class="col-5 text-end mb-0">{{ number_format($nextSevenDays) }}</dd>
                        </dl>
                    </div>

                    <div class="col-md-4">
                        <p class="text-uppercase text-muted small fw-semibold mb-2">Customer Response</p>
                        <dl class="row mb-0 small">
                            <dt class="col-7">Serial requested</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($serialRequested) }}</dd>
                            <dt class="col-7">Serial received</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($serialReceived) }}</dd>
                            <dt class="col-7">Still waiting</dt>
                            <dd class="col-5 text-end mb-0">{{ number_format($stillWaiting) }}</dd>
                        </dl>
                    </div>
                </div>

                <div class="mt-3 pt-3 border-top">
                    <p class="text-uppercase text-muted small fw-semibold mb-2">Team Workload</p>
                    @if ($workloadCards === [])
                        <p class="text-muted small mb-0">No active support workload right now.</p>
                    @else
                        <div class="row g-2 operations-workload-grid">
                            @foreach ($workloadCards as $member)
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div @class([
                                        'card border-0 shadow-sm h-100 operations-workload-card operations-card-hover',
                                        'operations-workload-card--' . $member['capacity_class'] => true,
                                    ])>
                                        <div class="card-body py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                <strong class="operations-workload-card-name">{{ $member['name'] }}</strong>
                                                <span @class(['status-badge', 'status-' . $member['capacity_class']])>
                                                    {{ number_format($member['load_percent']) }}%
                                                </span>
                                            </div>
                                            <div class="operations-workload-card-metrics small">
                                                <div class="d-flex justify-content-between gap-2">
                                                    <span class="text-muted">Active cases</span>
                                                    <strong>{{ number_format($member['active_cases']) }}</strong>
                                                </div>
                                                <div class="d-flex justify-content-between gap-2">
                                                    <span class="text-muted">Today</span>
                                                    <strong>{{ number_format($member['today']) }}</strong>
                                                </div>
                                                <div class="d-flex justify-content-between gap-2">
                                                    <span class="text-muted">Pending</span>
                                                    <strong>{{ number_format($member['pending']) }}</strong>
                                                </div>
                                            </div>
                                            <div class="operations-workload-card-meter mt-2">
                                                <div class="d-flex justify-content-between small text-muted mb-1">
                                                    <span>Capacity</span>
                                                    <span>{{ number_format($member['load_percent']) }}%</span>
                                                </div>
                                                <div class="progress operations-command-progress">
                                                    <div
                                                        class="progress-bar bg-{{ $member['progress_tone'] }}"
                                                        role="progressbar"
                                                        style="width: {{ $member['load_percent'] }}%;"
                                                        aria-valuenow="{{ $member['load_percent'] }}"
                                                        aria-valuemin="0"
                                                        aria-valuemax="100"
                                                    ></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
