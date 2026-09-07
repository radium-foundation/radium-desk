@extends('layouts.app')

@section('title', 'Hardware fulfilment')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Hardware fulfilment</h1>
        <p class="text-muted mb-0">Allocate serials and create Shiprocket shipments. Branch and pickup come from the selected stock serial.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Commerce</th>
                        <th>Status</th>
                        <th>Physical branch</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($fulfilments as $fulfilment)
                        <tr>
                            <td class="fw-semibold">{{ $fulfilment->source_id }}</td>
                            <td>{{ $fulfilment->commerceOrder?->order_no ?? '—' }}</td>
                            <td>{{ strtoupper(str_replace('_', ' ', $fulfilment->state?->value ?? '')) }}</td>
                            <td>{{ $fulfilment->fulfilmentBranch?->code ?? 'Unset' }}</td>
                            <td class="text-end">
                                <a href="{{ route('inventory.hardware-fulfilments.show', $fulfilment) }}">
                                    {{ $fulfilment->state?->value === 'ready_for_fulfilment' ? 'Allocate serial' : 'Open' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted">No eligible hardware fulfilments.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $fulfilments->links() }}</div>
@endsection
