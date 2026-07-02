@props([
    'failures' => [],
])

<section aria-labelledby="recent-notification-failures-heading">
    <h2 id="recent-notification-failures-heading" class="h5 mb-3">Recent Notification Failures</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($failures === [])
                <div class="p-4 text-center text-muted">No notification failures in the last 7 days.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Channel</th>
                                <th>Incident</th>
                                <th>Customer</th>
                                <th>Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($failures as $failure)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($failure['timestamp']) }}</td>
                                    <td>{{ $failure['channel'] }}</td>
                                    <td>{{ $failure['incident_reference'] ?? '—' }}</td>
                                    <td>{{ $failure['customer_name'] ?? '—' }}</td>
                                    <td class="small">{{ $failure['reason'] }}</td>
                                    <td class="text-end">
                                        @if($failure['incident_url'])
                                            <a href="{{ $failure['incident_url'] }}" class="btn btn-sm btn-outline-primary">Open Incident</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
