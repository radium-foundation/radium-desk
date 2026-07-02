@props([
    'components' => [],
])

<section class="mb-4" aria-labelledby="system-health-heading">
    <h2 id="system-health-heading" class="h5 mb-3">System Health</h2>

    <div class="row g-3">
        @foreach($components as $component)
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <h3 class="h6 mb-0">{{ $component['label'] }}</h3>
                            <span class="badge bg-{{ $component['badge_class'] }}">{{ $component['status_label'] }}</span>
                        </div>
                        <p class="text-muted small mb-0">{{ $component['detail'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
