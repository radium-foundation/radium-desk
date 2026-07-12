@props([
    'filterOptions' => [],
    'filters' => [],
])

<section class="mb-3" aria-labelledby="automation-filters-heading">
    <h2 id="automation-filters-heading" class="h5 mb-3">Recent Activity</h2>

    <form method="GET" action="{{ route('admin.operations.automation-health') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="automation-health-search" class="form-label small text-muted mb-1">Search</label>
                    <input
                        id="automation-health-search"
                        type="search"
                        name="search"
                        class="form-control form-control-sm"
                        value="{{ $filters['search'] ?? '' }}"
                        placeholder="Order, incident, customer, execution ID"
                    >
                </div>
                <div class="col-md-2">
                    <label for="automation-health-type" class="form-label small text-muted mb-1">Automation Type</label>
                    <select id="automation-health-type" name="automation_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach($filterOptions['automation_types'] ?? [] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['automation_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="automation-health-status" class="form-label small text-muted mb-1">Status</label>
                    <select id="automation-health-status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        @foreach($filterOptions['statuses'] ?? [] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="automation-health-date" class="form-label small text-muted mb-1">Date</label>
                    <input
                        id="automation-health-date"
                        type="date"
                        name="date"
                        class="form-control form-control-sm"
                        value="{{ $filters['date'] ?? '' }}"
                    >
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                    <a href="{{ route('admin.operations.automation-health') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>
    </form>
</section>
