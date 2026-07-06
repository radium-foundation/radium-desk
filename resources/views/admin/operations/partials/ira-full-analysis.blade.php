@props([
    'briefing' => null,
    'formatted' => null,
    'reasoningProvider' => 'rule_based',
    'advisorInsights' => [],
])

@if ($briefing === null || $formatted === null)
    <p class="text-muted mb-0">Ira analysis is not available right now.</p>
@else
    <div class="operations-ira-full-analysis">
        @include('admin.operations.partials.ira-briefing', [
            'briefing' => $briefing,
            'formatted' => $formatted,
            'reasoningProvider' => $reasoningProvider,
        ])

        <div class="mt-4">
            @include('admin.operations.partials.ira-briefing-details', [
                'briefing' => $briefing,
                'formatted' => $formatted,
            ])
        </div>

        <div class="mt-4">
            @include('admin.operations.partials.immediate-risks', [
                'briefing' => $briefing,
            ])
        </div>

        <div class="mt-4">
            @include('admin.operations.partials.advisor-insights', [
                'insights' => $advisorInsights,
            ])
        </div>
    </div>
@endif
