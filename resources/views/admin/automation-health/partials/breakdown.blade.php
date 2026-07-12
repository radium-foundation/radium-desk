@props([
    'breakdown' => [],
])

<section class="mb-4" aria-labelledby="automation-breakdown-heading">
    <h2 id="automation-breakdown-heading" class="h5 mb-3">Automation Breakdown</h2>

    <div class="row g-3">
        @foreach($breakdown as $item)
            <div class="col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 mb-3">{{ $item['label'] }}</h3>
                        <dl class="row small mb-0">
                            <dt class="col-7 text-muted">Executed today</dt>
                            <dd class="col-5 text-end mb-1">{{ number_format($item['executed_today'] ?? 0) }}</dd>
                            <dt class="col-7 text-muted">Succeeded</dt>
                            <dd class="col-5 text-end mb-1 text-success">{{ number_format($item['succeeded'] ?? 0) }}</dd>
                            <dt class="col-7 text-muted">Failed</dt>
                            <dd class="col-5 text-end mb-1 text-danger">{{ number_format($item['failed'] ?? 0) }}</dd>
                            <dt class="col-7 text-muted">Last execution</dt>
                            <dd class="col-5 text-end mb-0">{{ $item['last_execution_display'] ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
