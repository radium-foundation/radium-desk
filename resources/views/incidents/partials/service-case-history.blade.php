@props(['incident'])

@if($incident->order)
    @php
        $serviceCases = $incident->order->incidents->sortByDesc('created_at');
    @endphp

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">{{ config('ui.service_case.history_heading') }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ config('ui.service_case.reference_short') }}</th>
                        <th>Issue Summary</th>
                        <th>Status</th>
                        <th>Assigned Agent</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($serviceCases as $serviceCase)
                        <tr @class(['table-active' => $serviceCase->is($incident)])>
                            <td class="fw-semibold">
                                <a href="{{ route('incidents.show', $serviceCase) }}" class="text-decoration-none">
                                    {{ $serviceCase->display_reference }}
                                </a>
                            </td>
                            <td>{{ $serviceCase->issueSummary() }}</td>
                            <td>@include('incidents.partials.status-badge', ['status' => $serviceCase->status])</td>
                            <td>{{ $serviceCase->assignee?->firstName() ?: '—' }}</td>
                            <td class="text-nowrap">{{ display_app_datetime($serviceCase->created_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
