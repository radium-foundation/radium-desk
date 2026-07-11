<?php

namespace App\Contracts\AI;

use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;

interface CustomerJourneyMilestoneContributor
{
    /**
     * @return list<CustomerJourneyMilestoneDTO>
     */
    public function contribute(CustomerJourneyBuildContext $context): array;
}
