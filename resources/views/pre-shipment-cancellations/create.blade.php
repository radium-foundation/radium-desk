@extends('layouts.app')

@section('title', 'Pre-shipment cancel — '.$order->order_id)

@section('content')
    <div class="container py-4" style="max-width: 720px;">
        <h1 class="h4 mb-2">Pre-shipment cancellation</h1>
        <p class="text-muted small mb-3">
            Phase 2 State A+B only. Orders with serials allocated, statutory invoice issued, or AWB/shipment evidence are rejected.
        </p>

        @if (! $assessment['eligible'])
            <div class="alert alert-warning">
                <strong>Not eligible.</strong>
                @if ($assessment['code'] !== null)
                    Code: <code>{{ $assessment['code']->value }}</code>
                    @if (in_array($assessment['code']->value, ['serials_allocated', 'statutory_invoice_issued', 'shipment_or_awb', 'fulfilment_beyond_preship_scope'], true))
                        — State C/D or post–pre-ship fulfilment is not supported in Phase 2.
                    @endif
                @endif
            </div>
        @else
            <p class="small">
                Eligible phase: <strong>State {{ $assessment['phase'] }}</strong>
            </p>
        @endif

        @if ($paymentBreakdown !== null)
            <div class="card card-body mb-3 small">
                <h2 class="h6">Payment breakdown</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Order total</dt>
                    <dd class="col-sm-7">₹{{ number_format($paymentBreakdown->orderTotal, 2) }}</dd>
                    <dt class="col-sm-5">Cashfree / external tender</dt>
                    <dd class="col-sm-7">₹{{ number_format($paymentBreakdown->externalTender, 2) }}</dd>
                    <dt class="col-sm-5">Wallet tender</dt>
                    <dd class="col-sm-7">₹{{ number_format($paymentBreakdown->walletTender, 2) }}</dd>
                    <dt class="col-sm-5">Already refunded</dt>
                    <dd class="col-sm-7">₹{{ number_format($paymentBreakdown->alreadyRefunded, 2) }}</dd>
                    <dt class="col-sm-5">Maximum refundable</dt>
                    <dd class="col-sm-7">₹{{ number_format($paymentBreakdown->maximumRefundable, 2) }}</dd>
                </dl>
                @if ($paymentBreakdown->splitTender)
                    <div class="alert alert-danger mt-3 mb-0 py-2">
                        <strong>Split tender (Cashfree + Wallet).</strong>
                        Finance must manually review and execute each leg. Submission does not execute refunds.
                    </div>
                @endif
            </div>
        @elseif ($breakdownError !== null)
            <div class="alert alert-secondary small">{{ $breakdownError }}</div>
        @endif

        @if ($canSubmit)
            <form method="POST" action="{{ route('orders.pre-shipment-cancellations.store', $order) }}" class="card card-body">
                @csrf
                <div class="mb-3">
                    <label for="cancellation_reason" class="form-label">Cancellation reason</label>
                    <textarea name="cancellation_reason" id="cancellation_reason" class="form-control" rows="3" required>{{ old('cancellation_reason') }}</textarea>
                </div>
                <div class="mb-3">
                    <label for="requested_refund_amount" class="form-label">Requested refund (INR)</label>
                    <input type="number" step="0.01" name="requested_refund_amount" id="requested_refund_amount" class="form-control"
                           value="{{ old('requested_refund_amount', $paymentBreakdown->maximumRefundable) }}" min="0"
                           max="{{ $paymentBreakdown->maximumRefundable }}">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-danger">Submit cancellation</button>
                    <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Back to order</a>
                </div>
            </form>
        @else
            <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Back to order</a>
        @endif
    </div>
@endsection