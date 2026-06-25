@props(['incident'])

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">{{ config('ui.service_case.problem_description_heading') }}</h2>
    </div>
    <div class="card-body">
        <div class="service-case-description">{!! nl2br(e($incident->description)) !!}</div>
    </div>
</div>
