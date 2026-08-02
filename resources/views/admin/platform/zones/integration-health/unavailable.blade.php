@props([
    'label' => 'Integration',
    'message' => 'Unavailable',
    'lastSuccessfulUpdate' => null,
    'retryUrl' => null,
])

<div class="alert alert-secondary mb-0" role="status">
    <div class="fw-semibold mb-1">{{ $label }} · Unavailable</div>
    <p class="small mb-2">{{ $message }}</p>
    @if($lastSuccessfulUpdate)
        <p class="small text-muted mb-2">
            Last successful update
            {{ \App\Support\AppDateFormatter::format(\Illuminate\Support\Carbon::parse($lastSuccessfulUpdate), 'g:i A') }}
        </p>
    @endif
    @if($retryUrl)
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-platform-integration-expand
            data-expand-url="{{ $retryUrl }}"
            data-expand-target=""
        >
            Retry
        </button>
    @endif
</div>
