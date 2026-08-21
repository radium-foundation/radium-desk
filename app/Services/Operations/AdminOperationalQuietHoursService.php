<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;

class AdminOperationalQuietHoursService
{
    public function isQuietHours(?Carbon $at = null): bool
    {
        if (! (bool) config('ira.communication.admin_quiet_hours.enabled', true)) {
            return false;
        }

        $timezone = (string) config('app.schedule_timezone', config('app.timezone', 'Asia/Kolkata'));
        $at ??= Carbon::now($timezone);
        $at = $at->copy()->timezone($timezone);

        $start = (string) config('ira.communication.admin_quiet_hours.start', '18:30');
        $end = (string) config('ira.communication.admin_quiet_hours.end', '09:00');

        $startMinutes = $this->minutesFromTimeString($start);
        $endMinutes = $this->minutesFromTimeString($end);
        $currentMinutes = ($at->hour * 60) + $at->minute;

        if ($startMinutes === $endMinutes) {
            return false;
        }

        if ($startMinutes < $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes < $endMinutes;
        }

        return $currentMinutes >= $startMinutes || $currentMinutes < $endMinutes;
    }

    private function minutesFromTimeString(string $time): int
    {
        $normalized = strlen($time) === 5 ? $time.':00' : $time;
        $parts = explode(':', $normalized);

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
