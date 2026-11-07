@extends('layouts.app')

@section('title', 'Return & exchange management')

@section('content')
    <div class="container py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">Return &amp; exchange management</h1>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('return-exchange-management.demo.pos-6754') }}" class="btn btn-outline-secondary btn-sm">POS-6754 demo</a>
                <a href="{{ route('return-cases.index') }}" class="btn btn-outline-secondary btn-sm">Legacy case list</a>
                <a href="{{ route('return-exchange-management.create') }}" class="btn btn-primary btn-sm">New hybrid case</a>
            </div>
        </div>

        <p class="text-muted small mb-3">
            Hybrid policy workflow UI. Recording approvals and planned serials does not allocate stock, change invoices, or post finance journals.
        </p>

        <form method="GET" class="card card-body mb-3 small">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" class="form-control form-control-sm" value="{{ $filters['q'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">POS sale</label>
                    <input type="text" name="sale_no" class="form-control form-control-sm" value="{{ $filters['sale_no'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Serial</label>
                    <input type="text" name="serial" class="form-control form-control-sm" value="{{ $filters['serial'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->value }}" @selected(($filters['category'] ?? '') === $cat->value)>{{ $cat->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Case</th>
                        <th>Category</th>
                        <th>POS</th>
                        <th>Return serial</th>
                        <th>Planned replacement</th>
                        <th>Status</th>
                        <th>Branch</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cases as $case)
                        @php
                            $lineSerial = $case->lines->first()?->serial_number;
                            $pendingApprovals = $case->hybridApprovals?->where('decision', \App\Enums\ReturnExchangeHybridApprovalDecision::Pending)->count() ?? 0;
                        @endphp
                        <tr>
                            <td>{{ $case->case_no }}</td>
                            <td>{{ $case->replacement_category?->label() ?? '—' }}</td>
                            <td>{{ $case->inventorySale?->sale_no ?? $case->inventory_sale_id ?? '—' }}</td>
                            <td>{{ $lineSerial ?? '—' }}</td>
                            <td>{{ $case->planned_replacement_serial_number ?? '—' }}</td>
                            <td>
                                {{ $case->status->label() }}
                                @if ($pendingApprovals > 0)
                                    <span class="badge bg-warning text-dark">{{ $pendingApprovals }} approval(s)</span>
                                @endif
                            </td>
                            <td>{{ $case->branch?->name ?? '—' }}</td>
                            <td>{{ $case->updated_at?->format('d M Y H:i') }}</td>
                            <td><a href="{{ route('return-exchange-management.show', $case) }}">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-muted">No cases match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $cases->links() }}
    </div>
@endsection
