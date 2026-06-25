@extends('layouts.app')

@section('title', $incident->display_reference)

@section('content')
    <div data-service-case-show>
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('incidents.index') }}">{{ config('ui.service_case.plural') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $incident->display_reference }}</li>
            </ol>
        </nav>

        @include('incidents.partials.service-case-header', ['incident' => $incident])

        @include('incidents.partials.customer-order-summary', ['incident' => $incident])

        @include('incidents.partials.issue-summary', ['incident' => $incident])

        @include('incidents.partials.problem-description', ['incident' => $incident])

        @include('incidents.partials.activity-timeline', [
            'activityTimeline' => $activityTimeline,
            'incident' => $incident,
        ])

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-3">
                <h2 class="h6 mb-0">{{ config('ui.service_case.quick_actions_heading') }}</h2>
            </div>
            <div class="card-body py-3">
                @include('incidents.partials.quick-actions', ['incident' => $incident])
            </div>
        </div>

        @include('incidents.partials.related-information', ['incident' => $incident])

        @include('incidents.partials.service-case-history', ['incident' => $incident])

        <div class="service-case-sticky-bar d-none" data-sticky-bar>
            @include('incidents.partials.quick-actions', ['incident' => $incident, 'sticky' => true])
        </div>

        @include('incidents.partials.assign-modal', [
            'incident' => $incident,
            'reassignableAdmins' => $reassignableAdmins,
        ])

        @include('incidents.partials.remark-modal', [
            'incident' => $incident,
            'mentionUsers' => $mentionUsers,
        ])

        @include('incidents.partials.status-modals', ['incident' => $incident])
    </div>
@endsection
