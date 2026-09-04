@extends('layouts.app')

@section('title', 'Transfers')

@section('content')
    <div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Transfers</h1>
            <p class="text-muted mb-0">Move the same serial or quantity from one branch to another. Stock is never cloned.</p>
        </div>
        @if($canTransfer)
            <a href="{{ route('inventory.transfers.create') }}" class="btn btn-primary">New transfer</a>
        @endif
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'transfers'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Transfer</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transfers as $transfer)
                        <tr>
                            <td><a href="{{ route('inventory.transfers.show', $transfer) }}">{{ $transfer->transfer_no }}</a></td>
                            <td>{{ $transfer->fromBranch?->code }}</td>
                            <td>{{ $transfer->toBranch?->code }}</td>
                            <td>{{ $transfer->status->label() }}</td>
                            <td>{{ $transfer->completed_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted p-4">No transfers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $transfers->links() }}</div>
@endsection
