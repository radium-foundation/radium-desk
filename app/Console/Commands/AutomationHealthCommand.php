<?php

namespace App\Console\Commands;

use App\Services\ServiceCaseAutomationHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('automation:health')]
#[Description('Show automation pipeline health counts')]
class AutomationHealthCommand extends Command
{
    public function __construct(
        private readonly ServiceCaseAutomationHealthService $healthService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $counts = $this->healthService->counts();

        $this->line('Automation Pending: '.$counts['automation_pending']);
        $this->line('Waiting >5 min: '.$counts['waiting_over_5_min']);
        $this->line('Waiting >15 min: '.$counts['waiting_over_15_min']);
        $this->line('Unassigned: '.$counts['unassigned']);
        $this->line('Grace Expired: '.$counts['grace_expired']);
        $this->line('RadiumBox Pending: '.$counts['radiumbox_pending']);
        $this->line('Validation Failed: '.$counts['validation_failed']);
        $this->line('Assigned To Agent: '.$counts['assigned_to_agent']);
        $this->line('Assigned To Admin: '.$counts['assigned_to_admin']);

        return self::SUCCESS;
    }
}
