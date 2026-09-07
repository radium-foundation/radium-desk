@extends('layouts.app')

@section('title', 'Hardware fulfilment')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Hardware fulfilment</h1>
        <p class="text-muted mb-0">Allocate Desk stock serials before a hardware invoice can be issued. Serials are taken only from the fulfilment branch.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>State</th>
                        <th>Branch</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($fulfilments as $fulfilment)
                        <tr>
                            <td>{{ $fulfilment->source_id }}</td>
                            <td>{{ $fulfilment->state?->value }}</td>
                            <td>{{ $branches[$fulfilment->fulfilment_branch_id]->code ?? 'unset' }}</td>
                            <td class="text-end">
                                <a href="{{ route('inventory.hardware-fulfilments.show', $fulfilment) }}">Allocate serials</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted">No eligible hardware fulfilments.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $fulfilments->links() }}</div>
@endsection
