@extends('layouts.app')

@section('title', 'Pre-shipment cancellations — central queue')

@php
    use App\Support\PreShipmentCancellation\PreShipmentCancellationOperatorMetadataPresentation;
@endphp

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-3">Pre-shipment cancellation queue</h1>
        <p class="text-muted small">Finance and central operations. Refunds are not executed from this queue.</p>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Reference</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Split</th>
                    <th>Refund pref.</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($cases as $case)
                    @php
                        $queueMeta = PreShipmentCancellationOperatorMetadataPresentation::fromBlockerReason($case->blocker_reason);
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $case->reference_no }}</td>
                        <td>{{ $case->source_id }}</td>
                        <td><code>{{ $case->status->value }}</code></td>
                        <td>{{ $case->split_tender ? 'Yes' : 'No' }}</td>
                        <td>{{ $queueMeta['refund_preference_label'] ?? '—' }}</td>
                        <td>{{ optional($case->committed_at)->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>
                            <a href="{{ route('pre-shipment-cancellations.show', $case) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">No open pre-shipment cancellation cases.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{ $cases->links() }}
    </div>
@endsection