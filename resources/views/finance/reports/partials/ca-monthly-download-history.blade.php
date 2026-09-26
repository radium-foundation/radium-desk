<div class="card border-0 shadow-sm" id="ca-monthly-download-history">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h6 mb-0">Report Download History</h2>
            <span class="small text-muted">Super Admin audit · newest first</span>
        </div>

        @if ($history->isEmpty())
            <p class="small text-muted mb-0">No CA Monthly report export activity in the selected history window.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-nowrap">Downloaded By</th>
                            <th class="text-nowrap">Date/Time</th>
                            <th class="text-nowrap">Period</th>
                            <th class="text-nowrap">Format</th>
                            <th class="text-nowrap text-end">Records</th>
                            <th class="text-nowrap">Status</th>
                            <th class="text-nowrap text-end">Duration</th>
                            <th class="text-nowrap text-end">Export #</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr>
                                <td class="small">
                                    <div class="fw-medium">{{ $entry->user?->name ?? 'Unknown user' }}</div>
                                    <div class="text-muted">{{ $entry->user?->email ?? '—' }}</div>
                                </td>
                                <td class="small text-nowrap">
                                    {{ $entry->downloadHistoryTimestamp()?->timezone(config('app.timezone'))->format('j M Y, H:i:s') ?? '—' }}
                                </td>
                                <td class="small text-nowrap">{{ $entry->dateRangeLabel() }}</td>
                                <td class="small">
                                    <span class="badge text-bg-light border">{{ $entry->format->label() }}</span>
                                </td>
                                <td class="small text-end">{{ $entry->row_count ?? '—' }}</td>
                                <td class="small">
                                    @php
                                        $statusClass = match ($entry->status->value) {
                                            'ready' => $entry->downloaded_at ? 'text-bg-success' : 'text-bg-info',
                                            'failed' => 'text-bg-danger',
                                            'processing' => 'text-bg-primary',
                                            default => 'text-bg-secondary',
                                        };
                                    @endphp
                                    <span class="badge {{ $statusClass }}">{{ $entry->downloadHistoryStatusLabel() }}</span>
                                    @if ($entry->failure_message)
                                        <div class="text-danger mt-1">{{ $entry->failure_message }}</div>
                                    @endif
                                </td>
                                <td class="small text-end text-nowrap">{{ $entry->generationDurationLabel() }}</td>
                                <td class="small text-end text-muted">#{{ $entry->id }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($history->hasPages())
                <div class="mt-3">
                    {{ $history->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
