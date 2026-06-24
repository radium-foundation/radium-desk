@php
    $serviceCaseGroup = config('ui.service_case.plural');

    $label = match ($group) {
        'Orders' => $result->order_id,
        $serviceCaseGroup => $result->reference_no,
        'Approvals' => $result->approval_number,
        'Refunds' => $result->reference_no,
        default => 'View',
    };

    $url = match ($group) {
        'Orders' => route('orders.show', $result),
        $serviceCaseGroup => route('incidents.show', $result),
        'Approvals' => route('approvals.show', $result),
        'Refunds' => route('refunds.show', $result),
        default => '#',
    };

    $meta = match ($group) {
        'Orders' => collect([
            $result->serial_number,
            $result->transaction_id,
        ])->filter()->implode(' · '),
        $serviceCaseGroup => collect([
            $result->order?->order_id,
            $result->order?->serial_number,
            $result->order?->transaction_id,
            $result->title,
        ])->filter()->implode(' · '),
        'Approvals' => $result->description,
        'Refunds' => collect([
            $result->order?->order_id,
            $result->order?->serial_number,
            $result->order?->transaction_id,
            $result->status->label(),
        ])->filter()->implode(' · '),
        default => null,
    };
@endphp

<li class="search-result-item py-2 border-bottom">
    <a href="{{ $url }}" class="text-decoration-none d-flex align-items-start justify-content-between gap-3">
        <span>
            <span class="fw-semibold text-primary">{{ $label }}</span>
            @if($meta)
                <span class="d-block small text-muted mt-1">{{ $meta }}</span>
            @endif
        </span>
        <i class="bi bi-chevron-right text-muted flex-shrink-0 mt-1"></i>
    </a>
</li>
