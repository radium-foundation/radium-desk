<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360Trends
{
    /**
     * @param  list<array{date: string, value: int, label: string}>  $attendanceSeries
     * @param  list<array{date: string, value: int, label: string}>  $lateSeries
     * @param  list<array{date: string, value: int, label: string}>  $otSeries
     */
    public function __construct(
        public array $attendanceSeries,
        public array $lateSeries,
        public array $otSeries,
    ) {}
}
