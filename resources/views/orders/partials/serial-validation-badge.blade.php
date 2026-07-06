@props(['order', 'compact' => false])

@php
    use App\Enums\SerialValidationSeverity;
    use App\Services\SerialValidation\SerialPlaceholderService;
    use App\Services\SerialValidation\SerialValidationService;

    $validation = null;

    if (filled($order->serial_number) && ! app(SerialPlaceholderService::class)->isPlaceholder((string) $order->serial_number)) {
        $validation = app(SerialValidationService::class)->validateForOrder((string) $order->serial_number, $order);
    }
@endphp

@if($validation)
    @if($compact)
        @if($validation->severity === SerialValidationSeverity::Pass)
            <span class="serial-validation-indicator serial-validation-indicator--pass"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  data-bs-title="{{ $validation->reason ?? 'Serial verified' }}"
                  aria-label="Serial verified: {{ $validation->reason ?? 'Serial verified' }}">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            </span>
        @elseif($validation->severity === SerialValidationSeverity::Warning)
            <span class="serial-validation-indicator serial-validation-indicator--warning"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  data-bs-title="{{ $validation->reason }}"
                  aria-label="Serial validation warning: {{ $validation->reason }}">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            </span>
        @elseif($validation->severity === SerialValidationSeverity::Fail)
            <span class="serial-validation-indicator serial-validation-indicator--fail"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  data-bs-title="{{ $validation->reason }}"
                  aria-label="Serial validation failed: {{ $validation->reason }}">
                <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
            </span>
        @endif
    @else
        @if($validation->severity === SerialValidationSeverity::Pass)
            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle"
                  title="{{ $validation->reason ?? 'Serial verified' }}">
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Verified
            </span>
        @elseif($validation->severity === SerialValidationSeverity::Warning)
            <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                  title="{{ $validation->reason }}">
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Serial needs review
            </span>
        @elseif($validation->severity === SerialValidationSeverity::Fail)
            <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle"
                  title="{{ $validation->reason }}">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Serial validation failed
            </span>
        @endif
    @endif
@endif
