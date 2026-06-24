<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Recent Service Cases</h2>
        @can('viewAny', App\Models\Incident::class)
            <a href="{{ route('incidents.index') }}" class="btn btn-sm btn-outline-primary">View all</a>
        @endcan
    </div>
    <div class="card-body p-0">
        @if($recentServiceCases->isEmpty())
            <div class="p-3 text-center text-muted small">
                No service cases logged yet.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th class="d-none d-md-table-cell">Customer ID</th>
                            <th>Serial</th>
                            <th class="d-none d-lg-table-cell">Product</th>
                            <th>Status</th>
                            <th class="d-none d-sm-table-cell">Logged By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentServiceCases as $incident)
                            <tr>
                                <td class="fw-semibold">
                                    <a href="{{ route('incidents.show', $incident) }}" class="text-decoration-none">
                                        {{ $incident->reference_no }}
                                    </a>
                                </td>
                                <td class="d-none d-md-table-cell">{{ $incident->order?->customer_id ?: '—' }}</td>
                                <td>{{ $incident->order?->serial_number ?: '—' }}</td>
                                <td class="d-none d-lg-table-cell">{{ $incident->order?->product_name ?: '—' }}</td>
                                <td class="status-cell">
                                    @include('incidents.partials.status-badge', ['status' => $incident->status])
                                    @include('dashboard.partials.incident-aging-tooltip', ['incident' => $incident])
                                </td>
                                <td class="d-none d-sm-table-cell">{{ $incident->creator?->name ?? '—' }}</td>
                                <td class="text-nowrap">{{ $incident->created_at?->format('d M Y, h:i A') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
