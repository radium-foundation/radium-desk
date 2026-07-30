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

    $tooltipHtml = collect($tooltipLines)
        ->values()
        ->map(function (string $line, int $index): string {
            $class = $index === 0 ? 'att-tip__title' : ($index === 1 ? 'att-tip__status' : 'att-tip__line');

            return '<div class="'.$class.'">'.e($line).'</div>';
        })
        ->implode('');
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
