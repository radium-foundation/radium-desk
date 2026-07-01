<?php

namespace App\Services\Automation;

use App\Models\IncidentWaitingState;
use Closure;

class WaitingStateScanner
{
    /**
     * @param  Closure(IncidentWaitingState): void  $callback
     */
    public function scanActive(Closure $callback, int $chunkSize = 100): void
    {
        IncidentWaitingState::query()
            ->active()
            ->whereNotNull('reminder_policy_key')
            ->where('reminder_policy_key', '!=', '')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($waitingStates) use ($callback): void {
                foreach ($waitingStates as $waitingState) {
                    $callback($waitingState);
                }
            });
    }
}
