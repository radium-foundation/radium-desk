@props([
    'action',
    'period',
    'customStart' => null,
    'customEnd' => null,
])

<form method="GET" action="{{ $action }}" class="row g-2 align-items-end mb-4">
    <div class="col-md-3">
        <label for="performance-period" class="form-label small text-muted mb-1">Period</label>
        <select id="performance-period" name="period" class="form-select">
            @foreach(\App\Enums\PerformancePeriod::cases() as $periodOption)
                <option value="{{ $periodOption->value }}" @selected($period === $periodOption)>
                    {{ $periodOption->label() }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label for="performance-start-date" class="form-label small text-muted mb-1">Start date</label>
        <input type="date" id="performance-start-date" name="start_date" class="form-control"
               value="{{ $customStart }}">
    </div>
    <div class="col-md-3">
        <label for="performance-end-date" class="form-label small text-muted mb-1">End date</label>
        <input type="date" id="performance-end-date" name="end_date" class="form-control"
               value="{{ $customEnd }}">
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-primary w-100">Apply</button>
    </div>
</form>
