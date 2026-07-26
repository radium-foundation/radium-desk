@props([
    'teamAvailability' => ['on_duty' => [], 'unavailable' => []],
])

@php
    $onDutyMembers = collect($teamAvailability['on_duty'] ?? [])
        ->sortByDesc(fn (array $member): int => (int) ($member['open_work_count'] ?? 0))
        ->values()
        ->all();
    $activeCount = count($onDutyMembers);
    $topMembers = array_slice($onDutyMembers, 0, 4);
    $remainingCount = max(0, $activeCount - count($topMembers));
@endphp

<section class="operations-active-operators-compact h-100" aria-labelledby="operations-active-operators-heading">
    <div class="card border-0 shadow-sm operations-card-hover h-100">
        <div class="card-body py-3 d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div>
                    <h2 id="operations-active-operators-heading" class="h6 mb-0 fw-semibold">Active Operators</h2>
                    <div class="operations-bento-subtitle text-muted small">On duty now</div>
                </div>
                <span @class([
                    'status-badge',
                    'status-' . ($activeCount > 0 ? 'healthy' : 'info'),
                ])>
                    {{ number_format($activeCount) }} online
                </span>
            </div>

            @if ($topMembers === [])
                <p class="text-muted small mb-0 mt-auto">No operators are currently on duty.</p>
            @else
                <ul class="list-unstyled mb-0 mt-auto operations-active-operators-list">
                    @foreach ($topMembers as $member)
                        @php
                            $availability = $member['availability'] ?? [];
                            $availabilityClass = $availability['badge_class'] ?? 'secondary';
                            $statusTone = match ($availabilityClass) {
                                'success' => 'healthy',
                                'warning' => 'warning',
                                'danger' => 'danger',
                                default => 'info',
                            };
                            $openWork = (int) ($member['open_work_count'] ?? 0);
                        @endphp
                        <li class="operations-active-operator-row d-flex align-items-center justify-content-between gap-2 py-1">
                            <div class="d-flex align-items-center gap-2 min-w-0">
                                <span @class(['operations-team-status-dot', 'operations-team-status-dot--' . $statusTone]) aria-hidden="true"></span>
                                <span class="text-truncate">{{ $member['name'] ?? 'Unknown' }}</span>
                            </div>
                            <span class="text-muted small text-nowrap">{{ number_format($openWork) }} open</span>
                        </li>
                    @endforeach
                </ul>

                @if ($remainingCount > 0)
                    <p class="text-muted small mb-0 mt-1">+{{ number_format($remainingCount) }} more on duty</p>
                @endif
            @endif

            <button
                type="button"
                class="btn btn-sm btn-link px-0 mt-2 align-self-start"
                data-operations-tab-target="#operations-tab-team"
            >
                View workforce breakdown
            </button>
        </div>
    </div>
</section>
