<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360Profile
{
    /**
     * @param  list<WorkforceMember360TimelineDay>  $timeline
     * @param  list<WorkforceMember360Action>  $actions
     * @param  list<array{key: string, label: string, enabled: bool}>  $tabs
     */
    public function __construct(
        public WorkforceMember360Header $header,
        public WorkforceMember360AttendanceSummary $attendance,
        public WorkforceMember360LeaveSection $leave,
        public array $timeline,
        public WorkforceMember360Trends $trends,
        public array $actions,
        public array $tabs,
        public string $activeTab,
        public ?string $focusedDay,
        public string $profileUrl,
    ) {}
}
