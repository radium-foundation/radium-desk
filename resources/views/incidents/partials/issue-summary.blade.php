@props(['incident'])

<div class="mb-3">
    <h2 class="h6 text-muted text-uppercase small mb-2">{{ config('ui.service_case.issue_summary_heading') }}</h2>
    <p class="h4 fw-bold mb-0">{{ $incident->title }}</p>
</div>
