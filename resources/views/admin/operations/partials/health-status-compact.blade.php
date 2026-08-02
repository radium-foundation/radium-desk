@props([
    'cashfreeHealth' => [],
    'radiumBoxHealth' => [],
    'teamTelegramStatus' => [],
    'integrationHealth' => [],
])

{{-- Cached operational attention strip — full diagnostics live on Platform. --}}
<section class="operations-health-compact" aria-labelledby="operations-health-compact-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h2 id="operations-health-compact-heading" class="h6 mb-0 text-uppercase text-muted fw-semibold">Platform Health</h2>
            <p class="text-muted small mb-0">Monitoring dashboards moved to Platform. Use Operations for workflows.</p>
        </div>
        <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-outline-primary">
            Open Platform Dashboard
        </a>
    </div>
</section>
