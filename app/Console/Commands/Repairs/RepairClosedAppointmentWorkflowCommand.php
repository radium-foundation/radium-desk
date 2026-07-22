<?php

namespace App\Console\Commands\Repairs;

use App\Services\Repairs\Appointments\ClosedAppointmentWorkflowRepairDefinition;
use App\Support\Repair\Console\AbstractRepairCommand;
use App\Support\Repair\Contracts\RepairDefinition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('support-appointments:repair-closed-workflow
    {--dry-run : Preview repairs without mutating domain data}
    {--execute : Apply mutations (required for writes)}
    {--verify-only : Verify an existing batch}
    {--rollback : Rollback an existing batch}
    {--batch= : Batch UUID}
    {--limit= : Max candidates to process}
    {--offset=0 : Skip first N eligible candidates}
    {--resume : Resume from last checkpoint}
    {--checkpoint= : Checkpoint every N items}
    {--force : Skip confirmation prompts}
    {--summary-only : Summary only (no per-item progress)}
    {--json : Emit JSON summary on stdout}
    {--csv : Write CSV export}
    {--export= : Export directory override}
    {--since= : Preferred date lower bound (Y-m-d)}
    {--until= : Preferred date upper bound (Y-m-d)}
    {--notify-assignees : Allow assignee-side notifications}
    {--mode=auto : auto|full|cleanup|skip}
    {--today-action=full : full|cleanup|skip for today\'s appointments}
    {--include-past : Include past-date stale appointments}
    {--order= : Filter by order_id}
    {--incident= : Filter by incident reference or id}
    {--appointment= : Filter by support_appointments.id}
    {--shift-admin-only : Only shift-admin assignees}
    {--run-deferred=1 : Run deferred smart assignment after execute (1/0)}')]
#[Description('Repair closed service cases that still have scheduled Tech Support appointments')]
class RepairClosedAppointmentWorkflowCommand extends AbstractRepairCommand
{
    public function __construct(
        private readonly ClosedAppointmentWorkflowRepairDefinition $definition,
    ) {
        parent::__construct();
    }

    protected function repairDefinition(): RepairDefinition
    {
        return $this->definition;
    }

    protected function domainExtras(): array
    {
        return [
            'mode' => (string) ($this->option('mode') ?: 'auto'),
            'today_action' => (string) ($this->option('today-action') ?: 'full'),
            'include_past' => (bool) $this->option('include-past'),
            'order' => filled($this->option('order')) ? (string) $this->option('order') : null,
            'incident' => filled($this->option('incident')) ? (string) $this->option('incident') : null,
            'appointment' => filled($this->option('appointment')) ? (int) $this->option('appointment') : null,
            'shift_admin_only' => (bool) $this->option('shift-admin-only'),
            'run_deferred' => ((string) $this->option('run-deferred')) !== '0',
        ];
    }
}
