@props([
    'quality' => [],
])

@php
    $needSerial = (int) ($quality['need_serial'] ?? 0);
    $autoRequested = (int) ($quality['auto_requested'] ?? 0);
    $customerReplied = (int) ($quality['customer_replied'] ?? 0);
    $coordinatorFollowUp = (int) ($quality['coordinator_follow_up'] ?? 0);
@endphp

<section class="mb-0" aria-labelledby="missing-serial-automation-quality-heading">
    <h3 id="missing-serial-automation-quality-heading" class="h6 mb-3">Missing Serial Automation</h3>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-7">Need Serial</dt>
                <dd class="col-sm-5 text-end mb-2">{{ number_format($needSerial) }}</dd>

                <dt class="col-sm-7">Auto requested</dt>
                <dd class="col-sm-5 text-end mb-2">{{ number_format($autoRequested) }}</dd>

                <dt class="col-sm-7">Customer replied</dt>
                <dd class="col-sm-5 text-end mb-2">{{ number_format($customerReplied) }}</dd>

                <dt class="col-sm-7">Coordinator follow-up</dt>
                <dd class="col-sm-5 text-end mb-0">{{ number_format($coordinatorFollowUp) }}</dd>
            </dl>
        </div>
    </div>
</section>
