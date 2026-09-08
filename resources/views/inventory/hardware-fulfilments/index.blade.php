@extends('layouts.app')

@section('title', 'Hardware fulfilment')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Hardware fulfilment</h1>
        <p class="text-muted mb-0">Allocate serials and create Shiprocket shipments one order at a time. Branch and pickup come from the selected stock serial.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

    <form method="GET" action="{{ route('inventory.hardware-fulfilments.index') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="hf-filter-order">Order</label>
                    <input id="hf-filter-order" type="search" name="order" value="{{ $filters['order'] }}" class="form-control" placeholder="Order or source id">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="hf-filter-serial">Serial</label>
                    <input id="hf-filter-serial" type="search" name="serial" value="{{ $filters['serial'] }}" class="form-control" placeholder="Serial">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="hf-filter-state">Fulfilment state</label>
                    <select id="hf-filter-state" name="state" class="form-select">
                        <option value="">Open queue</option>
                        @foreach($states as $state)
                            <option value="{{ $state->value }}" @selected($filters['state'] === $state->value)>{{ strtoupper(str_replace('_', ' ', $state->value)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="hf-filter-branch">Branch</label>
                    <select id="hf-filter-branch" name="branch_id" class="form-select">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) $filters['branch_id'] === (string) $branch->id)>{{ $branch->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="hf-filter-shipment">Shipment / readiness</label>
                    <select id="hf-filter-shipment" name="shipment_status" class="form-select">
                        <option value="">All</option>
                        <option value="ready" @selected($filters['shipment_status'] === 'ready')>Invoice issued, not shipped</option>
                        <option value="not_created" @selected($filters['shipment_status'] === 'not_created')>No shipment</option>
                        <option value="created" @selected($filters['shipment_status'] === 'created')>Created, no AWB</option>
                        <option value="awb" @selected($filters['shipment_status'] === 'awb')>AWB assigned</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-primary w-100">Filter</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Commerce</th>
                        <th>Status</th>
                        <th>Physical branch</th>
                        <th>Shipment</th>
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
                            <td>
                                @if($fulfilment->awb)
                                    AWB {{ $fulfilment->awb }}
                                @elseif($fulfilment->shipment_id)
                                    Created
                                @else
                                    Not created
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('inventory.hardware-fulfilments.show', $fulfilment) }}">
                                    {{ $fulfilment->state?->value === 'ready_for_fulfilment' ? 'Allocate serial' : 'Open' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No eligible hardware fulfilments.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $fulfilments->links() }}</div>
@endsection
