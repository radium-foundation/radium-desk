<?php

use App\Support\AppDateFormatter;
use App\Support\DeviceModelFormatter;
use Carbon\CarbonInterface;

function app_timezone(): string
{
    return AppDateFormatter::timezone();
}

function display_app_date(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::date($date) ?? $fallback;
}

function display_app_datetime(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::datetime($date) ?? $fallback;
}

function display_app_datetime_24(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::datetime24($date) ?? $fallback;
}

function display_app_datetime_seconds(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::datetimeSeconds($date) ?? $fallback;
}

function display_app_remark_datetime(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::remarkDatetime($date) ?? $fallback;
}

function display_app_timeline_date(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::timelineDate($date) ?? $fallback;
}

function display_app_timeline_time(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::timelineTime($date) ?? $fallback;
}

function display_app_timeline_datetime(?CarbonInterface $date, string $fallback = '—'): string
{
    return AppDateFormatter::timelineDatetime($date) ?? $fallback;
}

function display_device_model_short(?string $fullModel, string $fallback = '—'): string
{
    return DeviceModelFormatter::shortDisplay($fullModel) ?? $fallback;
}
