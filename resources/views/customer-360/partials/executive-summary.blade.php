@php
    $translateUrl = route('dashboard.service-cases.customer-360.executive-summary.translate', $incident);
@endphp

@if($executiveSummary)
    <x-c360.ira-command-center
        :executiveSummary="$executiveSummary"
        :incident="$incident"
        :canRequestCorrectSerial="$canRequestCorrectSerial ?? false"
        :correctSerialRequestState="$correctSerialRequestState ?? ['requested' => false]"
        :translateUrl="$translateUrl"
    />
@else
    <x-c360.empty-state
        icon="bi-stars"
        title="IRA command center unavailable"
        description="Executive summary will load when IRA has enough data for this case."
        action-label="Open IRA AI"
        action-icon="bi-stars"
        data-c360-empty-open-tab="ai-assistant"
        class="c360-ira-command-center-empty"
    />
@endif
