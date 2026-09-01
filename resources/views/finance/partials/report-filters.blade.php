@props([
    'action',
    'filters' => [],
    'showSearch' => true,
    'showChannel' => true,
    'showStatus' => true,
])

<form method="get" action="{{ $action }}" class="row g-2 mb-3">
    <div class="col-md-2">
        <label class="form-label small text-muted mb-1" for="date_from">From</label>
        <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control">
    </div>
    <div class="col-md-2">
        <label class="form-label small text-muted mb-1" for="date_to">To</label>
        <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control">
    </div>
    @if($showSearch)
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1" for="q">Search</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Number, customer, GSTIN, source">
        </div>
    @endif
    @if($showChannel)
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="channel">Channel</label>
            <input type="text" id="channel" name="channel" value="{{ $filters['channel'] ?? '' }}" class="form-control" placeholder="desk_pos">
        </div>
    @endif
    @if($showStatus)
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="status">Status</label>
            <input type="text" id="status" name="status" value="{{ $filters['status'] ?? '' }}" class="form-control" placeholder="issued">
        </div>
    @endif
    <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-secondary w-100">Filter</button>
    </div>
</form>
