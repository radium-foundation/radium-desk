<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOperationsBrainService;
use Illuminate\Console\Command;

class SendIraOpsDigestCommand extends Command
{
    protected $signature = 'ira:send-ops-digest {--period=auto : Digest period key (open, close, or auto)}';

    protected $description = 'Send Ira operations digest via Telegram to operational recipients';

    public function handle(
        IraOperationsBrainService $brainService,
        IraCommunicationService $communicationService,
    ): int {
        $period = $this->resolvePeriod((string) $this->option('period'));
        $briefing = $brainService->briefing(useCache: false);
        $sentCount = 0;

        foreach ($communicationService->opsDigestRecipients() as $user) {
            $results = $communicationService->sendOpsDigest($user, $briefing, $period);
            $sentCount += count(array_filter(
                $results,
                fn ($notification) => $notification->status->value === 'sent',
            ));
        }

        $this->info("Ira operations digest ({$period}) processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }

    private function resolvePeriod(string $period): string
    {
        if (in_array($period, ['open', 'close'], true)) {
            return $period;
        }

        return (int) now()->format('G') < 14 ? 'open' : 'close';
    }
}
