<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">{{ config('ui.service_case.history_heading') }}</h2>
        @can('create', App\Models\Incident::class)
            <a href="{{ route('orders.service-cases.create', $order) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i> {{ config('ui.service_case.create_new_action') }}
            </a>
        @endcan
    </div>

    @if($order->incidents->isEmpty())
        <div class="card-body text-muted mb-0">{{ config('ui.service_case.history_empty') }}</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ config('ui.service_case.reference_short') }}</th>
                        <th>Issue Summary</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Assigned Agent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->incidents as $serviceCase)
                        <tr>
                            <td class="fw-semibold">
                                <a href="{{ route('incidents.show', $serviceCase) }}" class="text-decoration-none">
                                    {{ $serviceCase->display_reference }}
                                </a>
                            </td>
                            <td>{{ $serviceCase->issueSummary() }}</td>
                            <td>@include('incidents.partials.status-badge', ['status' => $serviceCase->status])</td>
                            <td class="text-nowrap">{{ display_app_datetime($serviceCase->created_at) }}</td>
                            <td>{{ $serviceCase->assignee?->firstName() ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card-footer bg-white border-top-0 pt-0 pb-3">
        @include('orders.partials.service-case-guidance')
    </div>
</div>
