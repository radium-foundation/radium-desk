@props([
    'calls' => [],
])

<section aria-labelledby="ivr-missed-calls-heading">
    <h2 id="ivr-missed-calls-heading" class="h5 mb-3">Missed Call Watch</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body p-0">
            @if(count($calls) === 0)
                <p class="text-muted mb-0 p-3">No recent missed calls.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Time</th>
                                <th scope="col">Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($calls as $call)
                                <tr>
                                    <td>{{ $call['customer_phone'] ?? '—' }}</td>
                                    <td>
                                        @php
                                            $startedAt = ! empty($call['started_at'])
                                                ? \Illuminate\Support\Carbon::parse($call['started_at'])
                                                : null;
                                        @endphp
                                        {{ display_app_datetime($startedAt) }}
                                    </td>
                                    <td>
                                        @if(! empty($call['order_url']))
                                            <a href="{{ $call['order_url'] }}" class="small">{{ $call['order_label'] }}</a>
                                        @else
                                            <span class="text-muted small">—</span>
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
