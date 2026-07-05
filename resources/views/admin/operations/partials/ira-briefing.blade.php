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

    $visibleHighlights = array_slice($briefing?->highlights ?? [], 0, 3);
    $hiddenHighlights = array_slice($briefing?->highlights ?? [], 3);
    $visibleRecommendations = array_slice($briefing?->recommendations ?? [], 0, 2);
    $hiddenRecommendations = array_slice($briefing?->recommendations ?? [], 2);
    $hasHiddenInsights = $hiddenHighlights !== [] || $hiddenRecommendations !== [];
@endphp

<section class="operations-ira-compact mb-0" id="ira-operations-briefing" aria-labelledby="ira-briefing-heading">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom-0 pb-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 id="ira-briefing-heading" class="h5 mb-1">Ira Today</h2>
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
                <p class="fw-semibold mb-1">{{ $briefing->greeting }}</p>
                <p class="text-muted small mb-3">{{ $briefing->summary }}</p>

                @if($visibleHighlights !== [])
                    <div class="mb-3">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Insights</h3>
                        <ul class="mb-0 ps-3 operations-ira-insight-list">
                            @foreach($visibleHighlights as $highlight)
                                <li>{{ $highlight }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($visibleRecommendations !== [])
                    <div class="mb-2">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Top Recommendations</h3>
                        <div class="d-flex flex-column gap-2">
                            @foreach($visibleRecommendations as $recommendation)
                                <div class="border rounded p-2 px-3 bg-light d-flex justify-content-between align-items-start gap-2">
                                    <div class="small">
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

                @if($hasHiddenInsights)
                    <div class="collapse" id="ira-hidden-insights">
                        @if($hiddenHighlights !== [])
                            <ul class="mb-3 ps-3 operations-ira-insight-list">
                                @foreach($hiddenHighlights as $highlight)
                                    <li>{{ $highlight }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if($hiddenRecommendations !== [])
                            <div class="d-flex flex-column gap-2 mb-2">
                                @foreach($hiddenRecommendations as $recommendation)
                                    <div class="border rounded p-2 px-3 bg-light d-flex justify-content-between align-items-start gap-2">
                                        <div class="small">
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
                        @endif
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 operations-ira-view-all"
                            data-bs-toggle="collapse"
                            data-bs-target="#ira-hidden-insights"
                            aria-expanded="false"
                            aria-controls="ira-hidden-insights"
                            data-operations-view-all-label="View all insights"
                            data-operations-view-less-label="Show fewer insights"
                        >
                            View all insights
                        </button>
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-muted"
                            data-operations-tab-target="#operations-tab-today"
                        >
                            Open in Today tab
                        </button>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
