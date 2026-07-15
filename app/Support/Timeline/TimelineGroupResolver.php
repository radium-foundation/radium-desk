<?php

namespace App\Support\Timeline;

use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;

final class TimelineGroupResolver
{
    /**
     * @return array{key: string, label: string, sort_key: int}
     */
    public static function resolve(Carbon $occurredAt, ?Carbon $reference = null): array
    {
        $reference ??= now(AppDateFormatter::timezone());
        $timezone = AppDateFormatter::timezone();

        $eventDay = $occurredAt->copy()->timezone($timezone)->startOfDay();
        $today = $reference->copy()->timezone($timezone)->startOfDay();
        $yesterday = $today->copy()->subDay();

        if ($eventDay->equalTo($today)) {
            return ['key' => 'today', 'label' => 'Today', 'sort_key' => 0];
        }

        if ($eventDay->equalTo($yesterday)) {
            return ['key' => 'yesterday', 'label' => 'Yesterday', 'sort_key' => 1];
        }

        $startOfWeek = $today->copy()->startOfWeek(Carbon::MONDAY);

        if ($eventDay->greaterThanOrEqualTo($startOfWeek)) {
            return [
                'key' => 'weekday:'.$eventDay->toDateString(),
                'label' => $eventDay->format('l'),
                'sort_key' => 10 + $today->diffInDays($eventDay),
            ];
        }

        $startOfLastWeek = $startOfWeek->copy()->subWeek();

        if ($eventDay->greaterThanOrEqualTo($startOfLastWeek)) {
            return ['key' => 'last-week', 'label' => 'Last Week', 'sort_key' => 100];
        }

        return ['key' => 'earlier', 'label' => 'Earlier', 'sort_key' => 200];
    }
}
