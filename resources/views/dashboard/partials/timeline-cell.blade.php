@php
    $timeline = display_app_grid_timeline_range($serviceCase->created_at, $serviceCase->updated_at);
    $tooltipParts = array_filter([
        $serviceCase->created_at ? 'Created: '.display_app_datetime($serviceCase->created_at) : null,
        $serviceCase->updated_at ? 'Updated: '.display_app_datetime($serviceCase->updated_at) : null,
    ]);
    $tooltip = implode(' · ', $tooltipParts);
@endphp

@if($timeline)
    <span class="dashboard-timeline-cell dashboard-u-datetime-compact"
          @if($tooltip)
              data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-title="{{ $tooltip }}"
          @endif>{{ $timeline }}</span>
@else
    —
@endif
