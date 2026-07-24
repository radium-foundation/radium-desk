@props([
    'dashboard',
])

<div data-automation-pipeline-embed>
    @include('admin.automation.partials.health', ['counts' => $dashboard->healthCounts])
    @include('admin.automation.partials.action-queues', ['dashboard' => $dashboard])
    @include('admin.automation.partials.recent-events', ['events' => $dashboard->recentAutomationEvents])
    @include('admin.automation.partials.repair-summary', ['statistics' => $dashboard->repairStatistics])
    @include('admin.automation.partials.validation-summary', [
        'byProduct' => $dashboard->validationByProduct,
        'byValidatorRule' => $dashboard->validationByValidatorRule,
        'byCategory' => $dashboard->validationByCategory,
    ])
</div>
