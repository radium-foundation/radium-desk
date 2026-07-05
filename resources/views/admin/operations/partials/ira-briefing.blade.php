@props([
    'briefing' => null,
    'reasoningProvider' => 'rule_based',
])

@php
    $healthClass = match ($briefing?->healthStatus ?? 'healthy') {
        'healthy' => 'success',
        'critical' => 'danger',
        default => 'warning',
    };

    $healthLabel = match ($briefing?->healthStatus ?? 'healthy') {
        'healthy' => 'Healthy',
        'critical' => 'Critical',
        default => 'Needs Attention',
    };
@endphp

<section class="mb-4" id="ira-operations-briefing" aria-labelledby="ira-briefing-heading">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom-0 pb-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 id="ira-briefing-heading" class="h5 mb-1">Ira Briefing</h2>
                    <p class="text-muted small mb-0">Operational intelligence from Ira Operations Brain</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-{{ $healthClass }}">{{ $healthLabel }}</span>
                    <span class="badge text-bg-light border text-muted">{{ str($reasoningProvider)->headline() }}</span>
                </div>
            </div>
        </div>

        @if($briefing === null)
            <div class="card-body">
                <p class="text-muted mb-0">Ira is preparing today&apos;s briefing.</p>
            </div>
        @else
            <div class="card-body pt-3">
                <p class="fw-semibold mb-2">{{ $briefing->greeting }}</p>
                <p class="mb-3">{{ $briefing->summary }}</p>

                @if($briefing->highlights !== [])
                    <div class="mb-3">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Today</h3>
                        <ul class="mb-0 ps-3">
                            @foreach($briefing->highlights as $highlight)
                                <li>{{ $highlight }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($briefing->risks !== [])
                    <div class="mb-3">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Risks ({{ count($briefing->risks) }})</h3>
                        <div class="d-flex flex-column gap-2">
                            @foreach($briefing->risks as $risk)
                                <div class="alert alert-{{ match($risk->severity) {
                                    \App\Enums\AI\AIRiskLevel::High => 'danger',
                                    \App\Enums\AI\AIRiskLevel::Medium => 'warning',
                                    default => 'secondary',
                                } }} mb-0 py-2 px-3 d-flex justify-content-between align-items-start gap-2">
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
                    </div>
                @endif

                @if($briefing->recommendations !== [])
                    <div>
                        <h3 class="h6 text-muted text-uppercase small mb-2">Recommendations</h3>
                        <div class="d-flex flex-column gap-2">
                            @foreach($briefing->recommendations as $recommendation)
                                <div class="border rounded p-3 bg-light d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        @if($recommendation->actionUrl)
                                            <a href="{{ $recommendation->actionUrl }}" class="text-decoration-none fw-medium">
                                                {{ $recommendation->message }}
                                            </a>
                                        @else
                                            <span class="fw-medium">{{ $recommendation->message }}</span>
                                        @endif
                                    </div>
                                    @include('admin.operations.partials.ira-feedback-buttons', [
                                        'insightKey' => $recommendation->key,
                                        'insightType' => 'recommendation',
                                        'insightPayload' => $recommendation->toArray(),
                                    ])
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
