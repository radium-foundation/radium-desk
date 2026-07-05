<?php

namespace App\Data\Operations;

readonly class MissingSerialAutomationQualitySummary
{
    public function __construct(
        public int $needSerial,
        public int $autoRequested,
        public int $customerReplied,
        public int $coordinatorFollowUp,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'need_serial' => $this->needSerial,
            'auto_requested' => $this->autoRequested,
            'customer_replied' => $this->customerReplied,
            'coordinator_follow_up' => $this->coordinatorFollowUp,
        ];
    }
}
