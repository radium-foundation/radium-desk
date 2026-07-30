@props(['trends'])

@php
    /** @var \App\Data\Workforce\WorkforceMember360Trends $trends */

    $renderSparkline = function (array $series, string $mode) {
        $usable = array_values(array_filter(
            $series,
            static fn (array $point): bool => ($point['value'] ?? -1) >= 0,
        ));

        if ($usable === []) {
            return '<div class="wm360-sparkline wm360-sparkline--empty">No data</div>';
        }

        $values = array_map(static fn (array $point): int => (int) $point['value'], $usable);
        $max = max($values);
        $width = max(120, count($usable) * 6);
        $height = 36;
        $bars = '';

        foreach ($usable as $index => $point) {
            $value = (int) $point['value'];
            $ratio = $max > 0 ? ($value / $max) : 0;
            $barHeight = $mode === 'binary'
                ? ($value > 0 ? 22 : 4)
                : max(3, (int) round($ratio * 28));
            $x = $index * 6;
            $y = $height - $barHeight;
            $fill = $mode === 'late' && $value > 0
                ? '#d97706'
                : ($mode === 'attendance' && $value >= 2 ? '#16a34a' : ($mode === 'attendance' && $value === 1 ? '#d97706' : '#94a3b8'));

            $bars .= sprintf(
                '<rect x="%d" y="%d" width="4" height="%d" rx="1" fill="%s"><title>%s</title></rect>',
                $x,
                $y,
                $barHeight,
                $fill,
                e(($point['date'] ?? '').' · '.($point['label'] ?? '')),
            );
        }

        return '<svg class="wm360-sparkline" viewBox="0 0 '.$width.' '.$height.'" width="100%" height="'.$height.'" role="img" aria-hidden="true">'.$bars.'</svg>';
    };
@endphp

<div class="wm360-trends">
    <div class="wm360-trend">
        <div class="wm360-trend__label">Attendance</div>
        {!! $renderSparkline($trends->attendanceSeries, 'attendance') !!}
    </div>
    <div class="wm360-trend">
        <div class="wm360-trend__label">Late</div>
        {!! $renderSparkline($trends->lateSeries, 'late') !!}
    </div>
    <div class="wm360-trend">
        <div class="wm360-trend__label">OT</div>
        {!! $renderSparkline($trends->otSeries, 'ot') !!}
    </div>
</div>
