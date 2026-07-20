@props([
    'openWork' => 0,
])

@php
    $openWork = (int) $openWork;
    $tone = $openWork >= 6 ? 'danger' : ($openWork >= 3 ? 'warning' : 'healthy');
    $label = $openWork === 1 ? '1 Case' : number_format($openWork).' Cases';
@endphp

{{--
  Phase 1: coloured case count.
  Future: add capacity indicators / progress bars inside .workforce360-workload without changing the grid cell.
--}}
<div class="workforce360-workload" data-workload-tone="{{ $tone }}" data-workload-count="{{ $openWork }}">
    <span @class([
        'workforce360-workload__value small',
        'workforce360-workload__value--danger' => $tone === 'danger',
        'workforce360-workload__value--warning' => $tone === 'warning',
        'workforce360-workload__value--healthy' => $tone === 'healthy',
    ])>{{ $label }}</span>
    <div class="workforce360-workload__meter" hidden aria-hidden="true"></div>
</div>
