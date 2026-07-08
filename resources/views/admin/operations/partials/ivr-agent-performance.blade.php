@props([
    'agents' => [],
])

<section aria-labelledby="ivr-agent-performance-heading">
    <h2 id="ivr-agent-performance-heading" class="h5 mb-3">Agent Performance</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body p-0">
            @if(count($agents) === 0)
                <p class="text-muted mb-0 p-3 small">No agent call activity today. Performance metrics appear after the first answered call.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle operations-ivr-agent-table">
                        <thead>
                            <tr>
                                <th scope="col">Agent</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col" class="text-end">Answered</th>
                                <th scope="col" class="text-end">Missed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($agents as $agent)
                                @php
                                    $missed = (int) ($agent['missed_count'] ?? 0);
                                    $total = (int) ($agent['total_calls'] ?? 0);
                                    $missedRate = $total > 0 ? ($missed / $total) * 100 : 0;
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $agent['agent_name'] }}</td>
                                    <td class="text-end">{{ number_format($total) }}</td>
                                    <td class="text-end text-success">{{ number_format($agent['answered_count'] ?? 0) }}</td>
                                    <td @class(['text-end', 'text-danger fw-semibold' => $missedRate >= 25, 'text-warning' => $missedRate >= 10 && $missedRate < 25])>
                                        {{ number_format($missed) }}
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
