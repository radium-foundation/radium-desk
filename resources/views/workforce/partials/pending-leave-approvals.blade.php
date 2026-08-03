@props([
    'pendingLeaveApprovals' => ['visible' => false, 'items' => []],
])

@php
    $visible = (bool) ($pendingLeaveApprovals['visible'] ?? false);
    $items = is_array($pendingLeaveApprovals['items'] ?? null) ? $pendingLeaveApprovals['items'] : [];
@endphp

@if($visible && $items !== [])
    <section class="mb-4" aria-label="Pending leave approvals">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Pending Leave Approvals</h2>
                <span class="badge text-bg-warning">{{ count($items) }}</span>
            </div>
            <ul class="list-group list-group-flush">
                @foreach($items as $item)
                    <li class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 py-3">
                        <div>
                            <div class="fw-semibold">{{ $item['employee'] }}</div>
                            <div class="text-muted small">
                                {{ $item['dates_label'] }}
                                <span class="mx-1">·</span>
                                {{ $item['age_label'] }}
                            </div>
                        </div>
                        <a href="{{ $item['review_url'] }}" class="btn btn-sm btn-primary">Review</a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
