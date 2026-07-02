@props([
    'activities' => [],
])

<section aria-labelledby="recent-automation-activity-heading">
    <h2 id="recent-automation-activity-heading" class="h5 mb-3">Recent Automation Activity</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($activities === [])
                <div class="p-4 text-center text-muted">No recent automation activity.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Trigger</th>
                                <th>Result</th>
                                <th>Duration</th>
                                <th>Channels</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activities as $activity)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($activity['timestamp']) }}</td>
                                    <td class="small">
                                        @if($activity['incident_url'])
                                            <a href="{{ $activity['incident_url'] }}" class="text-decoration-none">{{ $activity['incident_reference'] }}</a>
                                            <div class="text-muted">{{ $activity['trigger'] }}</div>
                                        @else
                                            {{ $activity['trigger'] }}
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $resultClass = match ($activity['result']) {
                                                'success' => 'success',
                                                'failed' => 'danger',
                                                'skipped' => 'secondary',
                                                default => 'warning',
                                            };
                                        @endphp
                                        <span class="badge bg-{{ $resultClass }}">{{ $activity['result_label'] }}</span>
                                    </td>
                                    <td class="text-nowrap">
                                        @if(($activity['duration_ms'] ?? null) !== null)
                                            {{ number_format($activity['duration_ms']) }} ms
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="small">{{ implode(', ', $activity['channels'] ?? []) ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
