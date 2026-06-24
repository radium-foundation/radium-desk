<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Recent Service Cases</h2>
        @can('viewAny', App\Models\Incident::class)
            <a href="{{ route('incidents.index') }}" class="btn btn-sm btn-outline-primary">{{ config('ui.service_case.view_all') }}</a>
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
                            <th>{{ config('ui.service_case.reference_short') }}</th>
                            <th>Order ID</th>
                            <th>Serial Number</th>
                            <th class="d-none d-lg-table-cell">Product</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th class="d-none d-md-table-cell">Transaction ID</th>
                            <th class="d-none d-sm-table-cell">Logged By</th>
                            <th>Created</th>
                            <th class="d-none d-md-table-cell">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentServiceCases as $serviceCase)
                            <tr>
                                <td class="fw-semibold">
                                    <div class="d-flex flex-wrap align-items-center gap-1">
                                        <a href="{{ route('incidents.show', $serviceCase) }}" class="text-decoration-none">
                                            {{ $serviceCase->reference_no }}
                                        </a>
                                        @if($serviceCase->high_priority)
                                            @include('dashboard.partials.high-priority-badge')
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $serviceCase->order?->order_id ?: '—' }}</td>
                                <td>{{ $serviceCase->order?->serial_number ?: '—' }}</td>
                                <td class="d-none d-lg-table-cell">{{ $serviceCase->order?->product_name ?: '—' }}</td>
                                <td>{{ $serviceCase->source->label() }}</td>
                                <td class="status-cell">
                                    @if($serviceCase->order)
                                        @include('dashboard.partials.completion-status-tooltip', [
                                            'order' => $serviceCase->order,
                                            'loggedAt' => $serviceCase->created_at,
                                        ])
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell">{{ $serviceCase->order?->transaction_id ?: '—' }}</td>
                                <td class="d-none d-sm-table-cell">{{ $serviceCase->creator?->firstName() ?: '—' }}</td>
                                <td class="text-nowrap">{{ $serviceCase->created_at?->format('d M Y, h:i A') ?: '—' }}</td>
                                <td class="d-none d-md-table-cell text-nowrap">{{ $serviceCase->updated_at?->format('d M Y, h:i A') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
