@extends('layouts.app')

@section('title', 'Inter-branch transfers')

@section('content')
    <div class="mb-4 d-flex justify-content-between align-items-start gap-3">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Inter-branch transfers</h1>
            <p class="text-muted mb-0">Branch stock movement with linked GST invoice — not a retail customer sale.</p>
        </div>
        @if($canCreate)
            <a href="{{ route('inventory.inter-branch-transfers.create') }}" class="btn btn-primary">New inter-branch transfer</a>
        @endif
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'inter-branch'])

    @include('inventory.partials.branch-scope-empty')

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Route</th>
                        <th>Status</th>
                        <th>Invoice</th>
                        <th>Issued</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td><a href="{{ route('inventory.inter-branch-transfers.show', $transaction) }}">{{ $transaction->transaction_no }}</a></td>
                            <td>{{ $transaction->fromBranch?->code }} → {{ $transaction->toBranch?->code }}</td>
                            <td>{{ $transaction->status->label() }}</td>
                            <td>{{ $transaction->statutoryInvoice?->invoice_number ?? '—' }}</td>
                            <td>{{ $transaction->issued_at?->format('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No inter-branch transfers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $transactions->links() }}</div>
@endsection
