@if($order->incidents->isEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">{{ config('ui.service_case.related_heading') }}</h2>
        </div>
        <div class="card-body text-muted mb-0">No service cases linked to this order yet.</div>
    </div>
@else
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">{{ config('ui.service_case.related_heading') }}</h2>
            <span class="badge text-bg-light text-dark border">{{ $order->incidents->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ config('ui.service_case.reference_short') }}</th>
                        <th>Source</th>
                        <th>Service Case Status</th>
                        <th>Logged By</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->incidents as $serviceCase)
                        <tr>
                            <td class="fw-semibold">
                                <a href="{{ route('incidents.show', $serviceCase) }}" class="text-decoration-none">
                                    {{ $serviceCase->reference_no }}
                                </a>
                            </td>
                            <td>{{ $serviceCase->source->label() }}</td>
                            <td>@include('incidents.partials.status-badge', ['status' => $serviceCase->status])</td>
                            <td>{{ $serviceCase->creator?->name ?? '—' }}</td>
                            <td class="text-nowrap">{{ $serviceCase->created_at?->format('d M Y, h:i A') ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
