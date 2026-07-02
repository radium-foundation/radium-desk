<?php

namespace App\Data\Knowledge;

use Illuminate\Support\Carbon;

readonly class OperationsKnowledgeDTO
{
    /**
     * @param  list<array{state: string, label: string, started_at: Carbon|null, cleared_at: Carbon|null}>  $slaHistory
     * @param  list<array{policy_key: string, action_type: string, status: string, occurred_at: Carbon|null}>  $automationHistory
     * @param  list<array{channel: string, status: string, occurred_at: Carbon|null}>  $notificationHistory
     * @param  list<array{reason: string, started_at: Carbon|null, cleared_at: Carbon|null}>  $waitingStateHistory
     */
    public function __construct(
        public array $slaHistory,
        public array $automationHistory,
        public array $notificationHistory,
        public array $waitingStateHistory,
    ) {}
}
