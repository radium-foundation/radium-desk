@props([
    'events' => [],
])

<section class="mb-4" aria-labelledby="recent-automation-heading">
    <h2 id="recent-automation-heading" class="h5 mb-3">Recent Automation</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($events === [])
                <div class="p-4 text-center text-muted">No recent automation events.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Time</th>
                                <th>Event</th>
                                <th>Case</th>
                                <th>Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($events as $event)
                                <tr>
                                    <td class="text-nowrap">{{ display_app_datetime_seconds($event['occurred_at']) }}</td>
                                    <td>{{ $event['label'] }}</td>
                                    <td>
                                        @if($event['case_url'])
                                            <a href="{{ $event['case_url'] }}" class="text-decoration-none">{{ $event['case_reference'] }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if($event['order_url'])
                                            <a href="{{ $event['order_url'] }}" class="text-decoration-none font-monospace">{{ $event['order_id'] }}</a>
                                        @else
                                            —
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
