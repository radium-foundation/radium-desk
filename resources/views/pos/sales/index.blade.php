@extends('layouts.app')

@section('title', 'POS sales')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
            <h1 class="h3 mb-1">Sale history</h1>
        </div>
        @if($canSell)
            <a href="{{ route('pos.counter.create') }}" class="btn btn-primary">New sale</a>
        @endif
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'sales'])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control" placeholder="Sale, invoice, phone, name" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <select name="branch_id" class="form-select">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach(\App\Enums\InventorySaleStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Sale</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sales as $sale)
                        <tr>
                            <td><a href="{{ route('pos.sales.show', $sale) }}">{{ $sale->sale_no }}</a></td>
                            <td>{{ $sale->invoice_number }}</td>
                            <td>{{ $sale->customer?->name }} · {{ $sale->customer?->phone }}</td>
                            <td>{{ $sale->branch?->code }}</td>
                            <td>{{ number_format((float) $sale->total, 2) }}</td>
                            <td>{{ $sale->status->label() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted p-4">No POS sales yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $sales->links() }}</div>
@endsection
