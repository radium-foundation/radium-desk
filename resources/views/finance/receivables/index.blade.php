@extends('layouts.app')

@section('title', 'Receivables')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Service receivables</h1>
        <p class="text-muted mb-0">Desk service statutory invoices. Finance journals remain off.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'receivables'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Allocated</th>
                        <th class="text-end">Outstanding</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td><a href="{{ route('finance.invoices.show', $row['invoice']) }}">{{ $row['invoice']->invoice_number }}</a></td>
                            <td>{{ $row['invoice']->buyer_name }}</td>
                            <td class="text-end">₹{{ number_format((float) $row['invoice']->invoice_value, 2) }}</td>
                            <td class="text-end">₹{{ number_format($row['allocated'], 2) }}</td>
                            <td class="text-end">₹{{ number_format($row['outstanding'], 2) }}</td>
                            <td><span class="badge text-bg-{{ $row['status']->value === 'paid' ? 'success' : ($row['status']->value === 'partial' ? 'warning' : 'secondary') }}">{{ strtoupper($row['status']->value) }}</span></td>
                            <td class="text-end">
                                @if($canRecordPayment && $row['outstanding'] > 0)
                                    <a href="{{ route('finance.payments.index', ['invoice_id' => $row['invoice']->id]) }}" class="btn btn-sm btn-primary">Record payment</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted p-4">No service invoices yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
