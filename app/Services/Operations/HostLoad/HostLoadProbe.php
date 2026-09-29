<?php

namespace App\Services\Operations\HostLoad;

class HostLoadProbe
{
    /**
     * @return array{load1: float, load5: float, load15: float}
     */
    public function loadAverages(): array
    {
        $averages = sys_getloadavg();

        if (! is_array($averages) || $averages === []) {
            return ['load1' => 0.0, 'load5' => 0.0, 'load15' => 0.0];
        }

        return [
            'load1' => round((float) ($averages[0] ?? 0.0), 2),
            'load5' => round((float) ($averages[1] ?? 0.0), 2),
            'load15' => round((float) ($averages[2] ?? 0.0), 2),
        ];
    }
}
