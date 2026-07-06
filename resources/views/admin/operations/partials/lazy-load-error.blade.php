@props([
    'message' => 'Unable to load this section.',
    'retryLabel' => 'Retry',
])

<div class="operations-lazy-error alert alert-warning mb-0 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
    <span>{{ $message }}</span>
    <button type="button" class="btn btn-sm btn-outline-warning" data-operations-lazy-retry>
        {{ $retryLabel }}
    </button>
</div>
