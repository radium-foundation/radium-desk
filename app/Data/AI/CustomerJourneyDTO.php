<?php

namespace App\Data\AI;

use App\Enums\AI\CustomerJourneyMilestoneType;

readonly class CustomerJourneyDTO
{
    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    public function __construct(
        public array $milestones,
        public CustomerJourneyConclusionDTO $conclusion,
        public CustomerJourneyConfidenceDTO $confidence,
    ) {}

    /**
     * @return list<string>
     */
    public function milestoneTitles(): array
    {
        return array_map(
            fn (CustomerJourneyMilestoneDTO $milestone): string => $milestone->title,
            $this->milestones,
        );
    }

    public function hasMilestone(CustomerJourneyMilestoneType $type): bool
    {
        foreach ($this->milestones as $milestone) {
            if ($milestone->type === $type) {
                return true;
            }
        }

        return false;
    }
}
