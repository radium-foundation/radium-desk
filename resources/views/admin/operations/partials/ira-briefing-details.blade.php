@props([
    'briefing' => null,
    'formatted' => null,
])

<section class="mb-4" aria-labelledby="ira-briefing-details-heading">
    <h2 id="ira-briefing-details-heading" class="h5 mb-3">Ira Insights</h2>

    @if($briefing === null || $formatted === null)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="text-muted mb-0">Ira is preparing today&apos;s briefing.</p>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                @include('admin.operations.partials.ira-briefing-sections', [
                    'formatted' => $formatted,
                ])

                @if($briefing->highlights !== [])
                    <div class="mt-4">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Additional Context</h3>
                        <ul class="mb-0 ps-3">
                            @foreach($briefing->highlights as $highlight)
                                <li>{{ $highlight }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif
</section>
