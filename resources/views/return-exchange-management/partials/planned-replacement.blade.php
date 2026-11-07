<div class="card mb-3">
    <div class="card-header py-2"><span class="small fw-semibold">Planned replacement (intent only)</span></div>
    <div class="card-body small">
        <p class="mb-2">Current: {{ $case->planned_replacement_serial_number ?? 'Not selected' }}</p>
        @if ($canPlanReplacement)
            <form method="POST" action="{{ route('return-exchange-management.planned-replacement', $case) }}" class="row g-2">
                @csrf
                <div class="col-md-5">
                    <input type="text" name="planned_replacement_serial_number" class="form-control form-control-sm" placeholder="Serial number" required>
                </div>
                <div class="col-md-3">
                    <input type="number" step="0.01" name="price_difference_amount" class="form-control form-control-sm" placeholder="Price diff">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100">Save plan</button>
                </div>
            </form>
        @else
            <p class="text-muted mb-0">You cannot plan a replacement for this case.</p>
        @endif
    </div>
</div>
