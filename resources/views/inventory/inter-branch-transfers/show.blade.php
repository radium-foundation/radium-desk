@extends('layouts.app')

@section('title', $transaction->transaction_no)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">{{ $transaction->transaction_no }}</h1>
        <p class="text-muted mb-0">
            {{ $transaction->fromBranch?->name }} → {{ $transaction->toBranch?->name }}
            · {{ $transaction->status->label() }}
        </p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'inter-branch'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Reconciliation</h2>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">GST invoice</dt>
                        <dd class="col-sm-8">{{ $transaction->statutoryInvoice?->invoice_number ?? '—' }}</dd>
                        <dt class="col-sm-4">Inventory transfer</dt>
                        <dd class="col-sm-8">{{ $transaction->inventoryTransfer?->transfer_no ?? '—' }}</dd>
                        <dt class="col-sm-4">Destination GSTIN</dt>
                        <dd class="col-sm-8">{{ $transaction->destination_gstin ?? '—' }}</dd>
                        <dt class="col-sm-4">E-way reference</dt>
                        <dd class="col-sm-8">
                            @if($transaction->eway_bill_reference)
                                {{ $transaction->eway_bill_reference }}
                                <span class="text-muted">({{ $transaction->eway_bill_status->label() }})</span>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Lifecycle</h2>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">Issued</dt>
                        <dd class="col-sm-8">{{ $transaction->issued_at?->format('d M Y H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">Dispatched</dt>
                        <dd class="col-sm-8">{{ $transaction->dispatched_at?->format('d M Y H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">Received</dt>
                        <dd class="col-sm-8">{{ $transaction->received_at?->format('d M Y H:i') ?? '—' }}</dd>
                        <dt class="col-sm-4">Completed</dt>
                        <dd class="col-sm-8">{{ $transaction->completed_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Serial</th>
                        <th>Qty</th>
                        <th>Unit price</th>
                        <th>GST %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transaction->lines as $line)
                        <tr>
                            <td>{{ $line->product?->sku }}</td>
                            <td>{{ $line->serial?->serial_number ?? '—' }}</td>
                            <td>{{ $line->qty }}</td>
                            <td>{{ number_format((float) $line->unit_price, 2) }}</td>
                            <td>{{ number_format((float) $line->gst_percentage, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($canDispatch)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Dispatch stock</h2>
                <form method="POST" action="{{ route('inventory.inter-branch-transfers.dispatch', $transaction) }}" class="row g-3">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label">Transporter</label>
                        <input type="text" name="transporter" class="form-control" value="{{ old('transporter') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Transport reference</label>
                        <input type="text" name="transport_reference" class="form-control" value="{{ old('transport_reference') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dispatch date</label>
                        <input type="date" name="dispatch_date" class="form-control" value="{{ old('dispatch_date', now()->toDateString()) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-way bill reference (entered manually — not API-generated)</label>
                        <input type="text" name="eway_bill_reference" class="form-control" value="{{ old('eway_bill_reference') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-way notes</label>
                        <input type="text" name="eway_bill_notes" class="form-control" value="{{ old('eway_bill_notes') }}">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Mark dispatched / in transit</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if($canReceive)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Receive at destination</h2>
                <form method="POST" action="{{ route('inventory.inter-branch-transfers.receive', $transaction) }}">
                    @csrf
                    <button class="btn btn-success">Confirm receipt at {{ $transaction->toBranch?->code }}</button>
                </form>
            </div>
        </div>
    @endif

    @if($canCancel)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 text-danger">Cancel (before dispatch only)</h2>
                <form method="POST" action="{{ route('inventory.inter-branch-transfers.cancel', $transaction) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <input type="text" name="cancel_reason" class="form-control" required>
                    </div>
                    <button class="btn btn-outline-danger">Cancel inter-branch transfer</button>
                </form>
            </div>
        </div>
    @endif
@endsection
