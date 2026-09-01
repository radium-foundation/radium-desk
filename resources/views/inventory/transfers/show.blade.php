@extends('layouts.app')

@section('title', $transfer->transfer_no)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">{{ $transfer->transfer_no }}</h1>
        <p class="text-muted mb-0">{{ $transfer->fromBranch?->name }} → {{ $transfer->toBranch?->name }} · {{ $transfer->status->label() }}</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'transfers'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Serial</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transfer->lines as $line)
                        <tr>
                            <td>{{ $line->product?->sku }}</td>
                            <td>{{ $line->serial?->serial_number ?? '—' }}</td>
                            <td>{{ $line->qty }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
