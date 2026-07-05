@props([
    'briefing' => null,
])

<section class="mb-4" aria-labelledby="operations-immediate-risks-heading">
    <h2 id="operations-immediate-risks-heading" class="h5 mb-3">Immediate Risks</h2>

    @if($briefing === null || $briefing->risks === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">No immediate operational risks detected.</p>
            </div>
        </div>
    @else
        <div class="d-flex flex-column gap-2">
            @foreach($briefing->risks as $risk)
                <div @class([
                    'alert mb-0 py-2 px-3 d-flex justify-content-between align-items-start gap-2',
                    'alert-danger' => $risk->severity === \App\Enums\AI\AIRiskLevel::High,
                    'alert-warning' => $risk->severity === \App\Enums\AI\AIRiskLevel::Medium,
                    'alert-secondary' => $risk->severity === \App\Enums\AI\AIRiskLevel::Low,
                ])>
                    <div>
                        <strong>{{ $risk->title }}</strong>
                        <div class="small">{{ $risk->message }}</div>
                    </div>
                    @include('admin.operations.partials.ira-feedback-buttons', [
                        'insightKey' => $risk->key,
                        'insightType' => 'risk',
                        'insightPayload' => $risk->toArray(),
                    ])
                </div>
            @endforeach
        </div>
    @endif
</section>
