@extends('layouts.app')

@section('title', 'Serials')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Serials</h1>
        <p class="text-muted mb-0">Each serial exists at exactly one branch. Status is available, reserved, sold, damaged, or returned.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'serials'])

    @include('inventory.partials.branch-scope-empty')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" name="q" class="form-control" placeholder="Serial" value="{{ $filters['q'] ?? '' }}">
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
                @foreach(\App\Enums\InventorySerialStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Serial</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Batch</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($serials as $serial)
                        <tr>
                            <td><a href="{{ route('inventory.serials.show', $serial) }}">{{ $serial->serial_number }}</a></td>
                            <td>{{ $serial->product?->sku }}</td>
                            <td>{{ $serial->branch?->code }}</td>
                            <td>{{ $serial->status->label() }}</td>
                            <td>{{ $serial->batch_code ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted p-4">No serials yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $serials->links() }}</div>
@endsection
