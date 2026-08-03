@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $items = is_array($card->meta['items'] ?? null) ? $card->meta['items'] : [];
@endphp

@if($items === [])
    <p class="text-muted small mb-0">No pending leave approvals.</p>
@else
    <ul class="list-unstyled mb-0 d-grid gap-3">
        @foreach($items as $item)
            <li class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-semibold">{{ $item['employee'] }}</div>
                    <div class="text-muted small">
                        {{ $item['dates_label'] }}
                        <span class="mx-1">·</span>
                        {{ $item['age_label'] }}
                    </div>
                </div>
                <a href="{{ $item['review_url'] }}" class="btn btn-sm btn-primary flex-shrink-0">Review</a>
            </li>
        @endforeach
    </ul>
@endif
