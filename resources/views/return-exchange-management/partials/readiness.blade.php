<div class="card mb-3">
    <div class="card-header py-2"><span class="small fw-semibold">Workflow readiness</span></div>
    <ul class="list-group list-group-flush small">
        @foreach ($checklist as $item)
            <li class="list-group-item d-flex justify-content-between align-items-start">
                <span>{{ $item->label }}</span>
                @if ($item->passed)
                    <span class="badge bg-success">OK</span>
                @else
                    <span class="badge bg-secondary">Blocked</span>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($blockingReasons !== [])
        <div class="card-body small text-muted">
            <p class="mb-1 fw-semibold">Blocking reasons</p>
            <ul class="mb-0">@foreach ($blockingReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        </div>
    @endif
</div>
