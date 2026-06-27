@php
    $slaStatus = $serviceCase->slaStatus();
@endphp

<span class="sla-status {{ $slaStatus->cssClass() }}"
      aria-label="{{ $slaStatus->label() }}"
      data-bs-toggle="tooltip"
      data-bs-placement="top"
      data-bs-html="true"
      data-bs-custom-class="dashboard-sla-tooltip"
      data-bs-title="{!! $serviceCase->slaTooltipHtml() !!}">
    <span class="sla-status-indicator" aria-hidden="true">{{ $slaStatus->indicator() }}</span>
    <span class="sla-status-label">{{ $slaStatus->label() }}</span>
</span>
