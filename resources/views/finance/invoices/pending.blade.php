@extends('layouts.app')

@section('title', 'Issue statutory invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Issue statutory invoices</h1>
        <p class="text-muted mb-0">Manual issue only. Automatic issuance stays off. POS INV-* receipts are not GST numbers. Historical Admin INV* invoices are not imported here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'issue'])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">POS sales</h2>
            <p class="small text-muted">Completed sales without a GST invoice. Internal INV-* receipts stay on the sale.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sale</th>
                            <th>Branch</th>
                            <th>Customer</th>
                            <th>Place of supply</th>
                            <th>Eligibility</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $row)
                            @php $sale = $row['sale']; $eligibility = $row['eligibility']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('pos.sales.show', $sale) }}">{{ $sale->sale_no }}</a>
                                    <div class="small text-muted">Receipt {{ $sale->invoice_number }}</div>
                                </td>
                                <td>{{ $sale->branch?->code }}</td>
                                <td>{{ $sale->customer?->name ?: '—' }}</td>
                                <td>{{ $sale->place_of_supply_state ?: '—' }}</td>
                                <td class="small">
                                    <div class="fw-semibold">{{ $eligibility->staffSummary() }}</div>
                                    @unless($eligibility->eligible)
                                        <div class="text-muted">{{ implode('; ', $eligibility->errors) }}</div>
                                    @endunless
                                </td>
                                <td class="text-end">
                                    @if($canIssue)
                                        <form method="POST" action="{{ route('finance.invoices.sales.issue', $sale) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-primary" @disabled(! $eligibility->eligible)>Issue tax invoice</button>
                                        </form>
                                    @else
                                        <span class="small text-muted">Admin issue only</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No completed POS sales waiting for a statutory invoice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6">Channel orders</h2>
            <p class="small text-muted">Commerce orders without a GST invoice. September 1, 2026 onward.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Channel</th>
                            <th>Payment</th>
                            <th>Eligibility</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $row)
                            @php $order = $row['order']; $eligibility = $row['eligibility']; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('finance.invoices.commerce-orders.show', $order) }}">{{ $order->order_no }}</a>
                                    <div class="small text-muted">{{ $order->source_id }}</div>
                                </td>
                                <td>{{ $order->channel->value }}</td>
                                <td>{{ $order->payment_status }}</td>
                                <td class="small">
                                    @if($eligibility->eligible)
                                        Ready to issue
                                    @else
                                        {{ implode('; ', $eligibility->errors) }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('finance.invoices.commerce-orders.show', $order) }}">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No pending channel orders.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
