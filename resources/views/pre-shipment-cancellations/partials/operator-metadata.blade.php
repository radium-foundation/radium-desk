@php
    use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadataPresentation;
    use App\Support\PreShipmentCancellation\PreShipmentCancellationRefundOperatorState;

    $operatorMeta = PreShipmentCancellationOperatorMetadataPresentation::fromBlockerReason($case->blocker_reason ?? null);
    $refundState = PreShipmentCancellationRefundOperatorState::resolve($case);
@endphp

<div class="card card-body small mt-3">
    <h2 class="h6">Refund status</h2>
    <p class="mb-1 fw-semibold">{{ $refundState['label'] }}</p>
    @if (filled($refundState['detail']))
        <p class="mb-0 text-muted text-break">{{ $refundState['detail'] }}</p>
    @endif
</div>

@if ($operatorMeta['refund_preference_label'] !== null || $operatorMeta['operator_notes'] !== null)
    <div class="card card-body small mt-3">
        <h2 class="h6">Operator submission</h2>
        <dl class="row mb-0">
            @if ($operatorMeta['refund_preference_label'] !== null)
                <dt class="col-sm-4">Refund preference</dt>
                <dd class="col-sm-8">{{ $operatorMeta['refund_preference_label'] }}</dd>
            @endif
            @if ($operatorMeta['operator_notes'] !== null)
                <dt class="col-sm-4">Operator notes</dt>
                <dd class="col-sm-8 text-break">{{ $operatorMeta['operator_notes'] }}</dd>
            @endif
        </dl>
    </div>
@endif

@if (filled($case->cancellation_reason))
    <div class="card card-body small mt-3">
        <h2 class="h6">Cancellation reason</h2>
        <p class="mb-0 text-break">{{ $case->cancellation_reason }}</p>
    </div>
@endif