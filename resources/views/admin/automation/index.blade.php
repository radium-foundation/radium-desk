@extends('layouts.app')

@section('title', 'Automation Operations')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Automation Operations</h1>
        <p class="text-muted mb-0">Read-only automation health, queues, and activity for administrators.</p>
    </div>

    @include('navigation.mission-control-workspace-nav', ['active' => 'automation'])

    @include('admin.automation.partials.dashboard-body', ['dashboard' => $dashboard])
@endsection
