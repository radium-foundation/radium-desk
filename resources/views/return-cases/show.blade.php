@extends('layouts.app')

@section('title', $case->case_no)

@section('content')
    <div class="container py-3 py-md-4" style="max-width: 840px;">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('return-cases.index') }}">Return cases</a></li>
                <li class="breadcrumb-item active">{{ $case->case_no }}</li>
            </ol>
        </nav>

        @if (session('status'))
            <div class="alert alert-success small" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger small" role="alert">
                <p class="mb-1 fw-semibold">Could not save — check the fields below.</p>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">{{ $case->case_no }}</h1>
                <p class="text-muted small mb-0">Internal staff only — not a customer portal. No tax, refund, or stock release here.</p>
            </div>
            <span class="badge bg-secondary">{{ $case->status->label() }}</span>
        </div>

        <div class="card mb-3">
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 col-sm-3">Source</dt>
                    <dd class="col-7 col-sm-9">{{ $case->source_kind->label() }}</dd>
                    <dt class="col-5 col-sm-3">Branch</dt>
                    <dd class="col-7 col-sm-9">{{ $case->branch?->name ?? 'Central / unassigned' }}</dd>
                    <dt class="col-5 col-sm-3">POS sale</dt>
                    <dd class="col-7 col-sm-9">{{ $case->inventory_sale_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Order</dt>
                    <dd class="col-7 col-sm-9">{{ $case->order_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Fulfilment</dt>
                    <dd class="col-7 col-sm-9">{{ $case->hardware_fulfilment_id ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Reason</dt>
                    <dd class="col-7 col-sm-9">{{ $case->reason }}</dd>
                    @if ($case->requester_remarks)
                        <dt class="col-5 col-sm-3">Internal remarks</dt>
                        <dd class="col-7 col-sm-9">{{ $case->requester_remarks }}</dd>
                    @endif
                    <dt class="col-5 col-sm-3">Requested by</dt>
                    <dd class="col-7 col-sm-9">{{ $case->requester?->name ?? '—' }}</dd>
                    <dt class="col-5 col-sm-3">Submitted</dt>
                    <dd class="col-7 col-sm-9">{{ $case->created_at?->format('d M Y H:i') }}</dd>
                </dl>
            </div>
        </div>

        <h2 class="h6">Requested items</h2>

        <div class="vstack gap-3 mb-4">
            @foreach ($case->lines as $line)
                <div class="card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <span class="small fw-semibold">Line {{ $line->line_no }} — {{ $line->description }}</span>
                        <span class="badge bg-light text-dark border">Qty {{ $line->qty }}</span>
                    </div>
                    <div class="card-body small">
                        <p class="mb-0">
                            <span class="text-muted">Expected serial:</span>
                            {{ $line->serial_number ?? 'Not specified on line' }}
                        </p>
                        @if ($line->notes)
                            <p class="mb-0 mt-2"><span class="text-muted">Notes:</span> {{ $line->notes }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
