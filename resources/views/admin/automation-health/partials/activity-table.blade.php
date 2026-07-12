@props([
    'activity',
])

<section aria-labelledby="automation-activity-heading" class="mb-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($activity->isEmpty())
                <div class="p-4 text-center text-muted">No automation executions match the current filters.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Automation</th>
                                <th>Action</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Triggered By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activity as $row)
                                <tr
                                    class="automation-health-row"
                                    role="button"
                                    tabindex="0"
                                    data-automation-health-detail-url="{{ $row['detail_url'] }}"
                                >
                                    <td class="text-nowrap small">{{ $row['timestamp_display'] }}</td>
                                    <td class="small">{{ $row['automation_label'] }}</td>
                                    <td class="small text-muted">{{ $row['action_label'] }}</td>
                                    <td class="small">{{ $row['subject'] }}</td>
                                    <td>
                                        @php
                                            $statusClass = match ($row['status']) {
                                                'success' => 'success',
                                                'failed' => 'danger',
                                                'skipped' => 'secondary',
                                                default => 'warning',
                                            };
                                        @endphp
                                        <span class="badge bg-{{ $statusClass }}">{{ $row['status_label'] }}</span>
                                    </td>
                                    <td class="text-nowrap small">{{ $row['duration_display'] }}</td>
                                    <td class="small text-muted">{{ $row['triggered_by'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($activity->hasPages())
                    <div class="p-3 border-top">
                        {{ $activity->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</section>
