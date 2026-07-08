@props([
    'agents' => [],
])

<section aria-labelledby="ivr-agent-performance-heading">
    <h2 id="ivr-agent-performance-heading" class="h5 mb-3">Agent Call Performance</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body p-0">
            @if(count($agents) === 0)
                <p class="text-muted mb-0 p-3">No agent call activity today.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
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
                                <tr>
                                    <td>{{ $agent['agent_name'] }}</td>
                                    <td class="text-end">{{ number_format($agent['total_calls'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($agent['answered_count'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($agent['missed_count'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
