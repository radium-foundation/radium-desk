<?php

namespace App\Jobs\Cashfree;

use App\Models\LegacyCashfreePaymentReconciliation;
use App\Services\Cashfree\LegacyCashfreePaymentReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLegacyCashfreePaymentReconciliationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $reconciliationId,
        public readonly bool $execute = false,
    ) {}

    public function handle(LegacyCashfreePaymentReconciliationService $service): void
    {
        if (! $service->enabled()) {
            return;
        }

        if ($this->execute && ! $service->executionEnabled()) {
            return;
        }

        $reconciliation = LegacyCashfreePaymentReconciliation::query()->find($this->reconciliationId);

        if ($reconciliation === null) {
            return;
        }

        $service->process($reconciliation, dryRun: ! $this->execute);

        usleep(1_500_000);
    }
}
