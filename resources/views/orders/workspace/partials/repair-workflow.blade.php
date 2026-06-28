@props([
    'order',
    'activeIncident' => null,
])

@php
    $stages = [
        ['key' => 'order', 'label' => 'Order'],
        ['key' => 'payment', 'label' => 'Payment'],
        ['key' => 'received', 'label' => 'Received'],
        ['key' => 'diagnosis', 'label' => 'Diagnosis'],
        ['key' => 'repair', 'label' => 'Repair'],
        ['key' => 'qc', 'label' => 'QC'],
        ['key' => 'ready', 'label' => 'Ready'],
        ['key' => 'pickup', 'label' => 'Pickup'],
    ];

    $isPaymentComplete = $order->isTransactionLocked();
    $hasActiveRepair = $activeIncident !== null;

    $currentIndex = match (true) {
        ! $isPaymentComplete => 1,
        $hasActiveRepair => 4,
        default => 2,
    };
@endphp

<nav class="order-workspace-workflow" aria-label="Repair workflow progress">
    <ol class="order-workspace-workflow-list">
        @foreach($stages as $index => $stage)
            @php
                $state = match (true) {
                    $index < $currentIndex => 'complete',
                    $index === $currentIndex => 'current',
                    default => 'upcoming',
                };
            @endphp
            <li @class([
                'order-workspace-workflow-step',
                'order-workspace-workflow-step--'.$state,
            ])>
                <span class="order-workspace-workflow-marker" aria-hidden="true">
                    @if($state === 'complete')
                        <i class="bi bi-check-lg"></i>
                    @else
                        <span class="order-workspace-workflow-dot"></span>
                    @endif
                </span>
                <span class="order-workspace-workflow-label">{{ $stage['label'] }}</span>
            </li>
            @if(! $loop->last)
                <li class="order-workspace-workflow-connector" aria-hidden="true"></li>
            @endif
        @endforeach
    </ol>
</nav>
