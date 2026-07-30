@props([
    'cell',
    'columnMuted' => false,
])

@php
    /** @var \App\Data\Workforce\AttendanceMatrixCell $cell */
    $classes = [
        'attendance-matrix-cell',
        'attendance-matrix-cell--'.$cell->kind->value,
        'attendance-matrix-cell--tone-'.$cell->tone,
    ];

    if ($columnMuted) {
        $classes[] = 'attendance-matrix-cell--column-muted';
    }

    if ($cell->disabled) {
        $classes[] = 'is-disabled';
    }

    $tooltipLines = array_values(array_filter(array_map(
        static fn (string $part): string => trim($part),
        explode(' · ', (string) $cell->tooltip),
    ), static fn (string $part): bool => $part !== ''));

    $tooltipHtml = '<div class="att-tip">'.collect($tooltipLines)
        ->values()
        ->map(function (string $line, int $index): string {
            if ($index === 0) {
                return '<div class="att-tip__title">'.e($line).'</div>';
            }

            if ($index === 1) {
                return '<div class="att-tip__status">'.e($line).'</div>';
            }

            if ($index === 2) {
                return '<div class="att-tip__body"><div class="att-tip__line">'.e($line).'</div>';
            }

            return '<div class="att-tip__line">'.e($line).'</div>';
        })
        ->implode('')
        .(count($tooltipLines) > 2 ? '</div>' : '')
        .'</div>';
@endphp

@if($cell->interactive)
    <button
        type="button"
        @class($classes)
        aria-label="{{ $cell->tooltip }}"
        data-bs-toggle="tooltip"
        data-bs-html="true"
        data-bs-placement="top"
        data-bs-custom-class="attendance-premium-tooltip"
        data-bs-title="{{ $tooltipHtml }}"
        data-attendance-cell
        data-attendance-drawer-trigger
        data-user-id="{{ $cell->userId }}"
        data-work-date="{{ $cell->workDate }}"
        data-kind="{{ $cell->kind->value }}"
        data-drawer-payload='@json($cell->drawerPayload)'
    >
        <span class="attendance-matrix-badge attendance-matrix-badge--{{ $cell->tone }}">
            {{ $cell->shortLabel }}
        </span>
    </button>
@else
    <span
        @class($classes)
        aria-label="{{ $cell->tooltip }}"
        data-bs-toggle="tooltip"
        data-bs-html="true"
        data-bs-placement="top"
        data-bs-custom-class="attendance-premium-tooltip"
        data-bs-title="{{ $tooltipHtml }}"
        data-attendance-cell
        data-user-id="{{ $cell->userId }}"
        data-work-date="{{ $cell->workDate }}"
        data-kind="{{ $cell->kind->value }}"
    >
        <span class="attendance-matrix-badge attendance-matrix-badge--muted">
            {{ $cell->shortLabel }}
        </span>
    </span>
@endif
