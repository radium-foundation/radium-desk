@extends('layouts.app')

@section('title', 'Automation Operations')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Automation Operations</h1>
        <p class="text-muted mb-0">Read-only automation health, queues, and activity for administrators.</p>
    </div>

    @include('admin.automation.partials.health', ['counts' => $dashboard->healthCounts])
    @include('admin.automation.partials.action-queues', ['dashboard' => $dashboard])
    @include('admin.automation.partials.recent-events', ['events' => $dashboard->recentAutomationEvents])
    @include('admin.automation.partials.repair-summary', ['statistics' => $dashboard->repairStatistics])
    @include('admin.automation.partials.validation-summary', [
        'byProduct' => $dashboard->validationByProduct,
        'byValidatorRule' => $dashboard->validationByValidatorRule,
        'byCategory' => $dashboard->validationByCategory,
    ])
@endsection
