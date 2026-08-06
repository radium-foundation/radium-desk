@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $pendingToday = (int) ($card->meta['pending_today'] ?? 0);
    $pendingYesterday = (int) ($card->meta['pending_yesterday'] ?? 0);
    $pendingTotal = (int) ($card->meta['pending_total'] ?? 0);
@endphp

<div class="d-grid gap-2">
    <div class="d-flex justify-content-between align-items-baseline gap-2">
        <span class="text-muted small">Pending Today</span>
        <span class="fw-semibold">{{ $pendingToday }}</span>
    </div>
    <div class="d-flex justify-content-between align-items-baseline gap-2">
        <span class="text-muted small">Pending Yesterday</span>
        <span class="fw-semibold">{{ $pendingYesterday }}</span>
    </div>
    <div class="d-flex justify-content-between align-items-baseline gap-2 border-top pt-2">
        <span class="text-muted small">Total Pending</span>
        <span class="fw-semibold fs-5">{{ $pendingTotal }}</span>
    </div>
</div>
