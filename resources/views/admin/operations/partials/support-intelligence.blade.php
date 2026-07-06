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
@endphp

<section class="mb-0" aria-labelledby="support-intelligence-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h3 id="support-intelligence-heading" class="h6 mb-0">Support Intelligence</h3>
        <button
            class="btn btn-sm btn-outline-secondary"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#operations-support-intelligence-details"
            aria-expanded="{{ ($missedOverdue > 0 || $stillWaiting > 0) ? 'true' : 'false' }}"
            aria-controls="operations-support-intelligence-details"
        >
            {{ ($missedOverdue > 0 || $stillWaiting > 0) ? 'Hide details' : 'Show details' }}
        </button>
    </div>

    <div class="operations-support-intelligence-summary card border-0 shadow-sm mb-2">
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

    <div id="operations-support-intelligence-details" @class(['collapse', 'show' => ($missedOverdue > 0 || $stillWaiting > 0)])>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-4">
                <div class="col-md-6 col-xl-3">
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

                <div class="col-md-6 col-xl-3">
                    <p class="text-uppercase text-muted small fw-semibold mb-2">Upcoming Support</p>
                    <dl class="row mb-0 small">
                        <dt class="col-7">Tomorrow</dt>
                        <dd class="col-5 text-end mb-1">{{ number_format($tomorrow) }}</dd>
                        <dt class="col-7">Next 7 days</dt>
                        <dd class="col-5 text-end mb-0">{{ number_format($nextSevenDays) }}</dd>
                    </dl>
                </div>

                <div class="col-md-6 col-xl-3">
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

                <div class="col-md-6 col-xl-3">
                    <p class="text-uppercase text-muted small fw-semibold mb-2">Team Workload</p>
                    @if ($teamWorkload === [])
                        <p class="text-muted small mb-0">No support team members configured.</p>
                    @else
                        <ul class="list-unstyled mb-0 small">
                            @foreach ($teamWorkload as $member)
                                @if (($member['today'] ?? 0) > 0 || ($member['pending'] ?? 0) > 0)
                                    <li class="d-flex justify-content-between gap-2 mb-1">
                                        <span class="text-truncate">{{ $member['name'] ?? 'Unknown' }}</span>
                                        <span class="text-nowrap text-muted">
                                            Today: {{ number_format((int) ($member['today'] ?? 0)) }},
                                            Pending: {{ number_format((int) ($member['pending'] ?? 0)) }}
                                        </span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
    </div>
</section>
