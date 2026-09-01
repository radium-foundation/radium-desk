@extends('layouts.app')

@section('title', 'Movements')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Movement ledger</h1>
        <p class="text-muted mb-0">Append-only inventory history. Transfers always record source and destination.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'movements'])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="text" name="serial" class="form-control" placeholder="Serial" value="{{ $filters['serial'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <select name="type" class="form-select">
                <option value="">All types</option>
                @foreach(\App\Enums\InventoryMovementType::cases() as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
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
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Product</th>
                        <th>Serial</th>
                        <th>Branch</th>
                        <th>Qty</th>
                        <th>Actor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($movements as $movement)
                        <tr>
                            <td>{{ $movement->occurred_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>{{ $movement->type->label() }}</td>
                            <td>{{ $movement->product?->sku }}</td>
                            <td>
                                @if($movement->serial)
                                    <a href="{{ route('inventory.serials.show', $movement->serial) }}">{{ $movement->serial->serial_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $movement->branch?->code }}</td>
                            <td>{{ $movement->qty }}</td>
                            <td>{{ $movement->actor?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted p-4">No movements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $movements->links() }}</div>
@endsection
