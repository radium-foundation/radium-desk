@props(['source'])

@php
    $symbol = $source->badgeSymbol();
    $abbreviation = $source->badgeAbbreviation();
    $showAbbreviation = $symbol === null || ! in_array($abbreviation, ['👤', '🌐'], true);
@endphp

<span class="dashboard-source-badge"
      role="img"
      aria-label="{{ $source->label() }}"
      data-bs-toggle="tooltip"
      data-bs-placement="top"
      data-bs-title="{{ $source->label() }}">
    @if($symbol)
        <span class="dashboard-source-badge__symbol" aria-hidden="true">{{ $symbol }}</span>
    @endif
    @if($showAbbreviation)
        <span class="dashboard-source-badge__abbr" aria-hidden="true">{{ $abbreviation }}</span>
    @endif
</span>
