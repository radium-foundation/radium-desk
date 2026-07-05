@props([
    'briefing' => null,
])

<section class="mb-4" aria-labelledby="ira-briefing-details-heading">
    <h2 id="ira-briefing-details-heading" class="h5 mb-3">Ira Insights</h2>

    @if($briefing === null)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">Ira is preparing today&apos;s briefing.</p>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body">
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

                @if($briefing->highlights === [] && $briefing->recommendations === [])
                    <p class="text-muted mb-0">No additional Ira insights for today.</p>
                @endif
            </div>
        </div>
    @endif
</section>
