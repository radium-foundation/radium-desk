<?php

namespace App\Support;

use Carbon\CarbonInterface;

class AppDateFormatter
{
    public static function timezone(): string
    {
        return config('app.timezone', 'Asia/Kolkata');
    }

    public static function inAppTimezone(?CarbonInterface $date): ?CarbonInterface
    {
        if ($date === null) {
            return null;
        }

        return $date->copy()->timezone(self::timezone());
    }

    public static function format(?CarbonInterface $date, string $format): ?string
    {
        return self::inAppTimezone($date)?->format($format);
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M Y');
    }

    public static function datetime(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M Y, h:i A');
    }

    public static function datetime24(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M Y, H:i');
    }

    public static function datetimeSeconds(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M Y, H:i:s');
    }

    public static function remarkDatetime(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd-M-Y h:i A');
    }

    public static function timelineDate(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M Y');
    }

    public static function timelineTime(?CarbonInterface $date): ?string
    {
        return self::format($date, 'h:i A');
    }

    public static function timelineDatetime(?CarbonInterface $date): ?string
    {
        $formattedDate = self::timelineDate($date);
        $formattedTime = self::timelineTime($date);

        if ($formattedDate === null || $formattedTime === null) {
            return null;
        }

        return "{$formattedDate} • {$formattedTime}";
    }

    public static function timelineRelative(?CarbonInterface $date): ?string
    {
        $localized = self::inAppTimezone($date);

        return $localized?->diffForHumans();
    }

    public static function timelineOperatorRelative(?CarbonInterface $date): ?string
    {
        $localized = self::inAppTimezone($date);

        if ($localized === null) {
            return null;
        }

        $now = now(self::timezone());
        $elapsedSeconds = $localized->diffInSeconds($now, false);

        if ($elapsedSeconds <= 59) {
            return 'Just now';
        }

        if ($elapsedSeconds <= 3599) {
            $minutes = max(1, (int) floor($elapsedSeconds / 60));

            return "{$minutes} min ago";
        }

        $today = $now->copy()->startOfDay();
        $eventDay = $localized->copy()->startOfDay();
        $time = self::timelineTime($localized) ?? $localized->format('h:i A');

        if ($eventDay->equalTo($today)) {
            return "Today • {$time}";
        }

        if ($eventDay->equalTo($today->copy()->subDay())) {
            return "Yesterday • {$time}";
        }

        $dateLabel = self::format($localized, 'j M') ?? $localized->format('j M');

        return "{$dateLabel} • {$time}";
    }

    public static function gridCompactDatetime(?CarbonInterface $date): ?string
    {
        return self::format($date, 'd M H:i');
    }

    public static function waitingDuration(?CarbonInterface $startedAt): ?string
    {
        $localized = self::inAppTimezone($startedAt);

        if ($localized === null) {
            return null;
        }

        return $localized->diffForHumans(now(self::timezone()), [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 1,
            'short' => false,
        ]);
    }
}
