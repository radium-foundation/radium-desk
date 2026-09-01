@extends('layouts.app')

@section('title', 'Serial '.$serial->serial_number)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">{{ $serial->serial_number }}</h1>
        <p class="text-muted mb-0">{{ $serial->product?->sku }} · {{ $serial->branch?->name }} · {{ $serial->status->label() }}</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'serials'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h5">Movement history</h2>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Branch</th>
                            <th>From → To</th>
                            <th>Qty</th>
                            <th>Actor</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movements as $movement)
                            <tr>
                                <td>{{ $movement->occurred_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                <td>{{ $movement->type->label() }}</td>
                                <td>{{ $movement->branch?->code }}</td>
                                <td>
                                    @if($movement->fromBranch || $movement->toBranch)
                                        {{ $movement->fromBranch?->code ?? '—' }} → {{ $movement->toBranch?->code ?? '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $movement->qty }}</td>
                                <td>{{ $movement->actor?->name ?? '—' }}</td>
                                <td>{{ $movement->notes ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted">No movements recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
