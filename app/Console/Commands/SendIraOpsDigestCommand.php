<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOperationsBrainService;
use Illuminate\Console\Command;

class SendIraOpsDigestCommand extends Command
{
    protected $signature = 'ira:send-ops-digest {--period=auto : Digest period key (morning, evening, open, close, or auto)}';

    protected $description = 'Send Ira operations summary via Telegram to operational recipients';

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

        $this->info("Ira operations summary ({$period}) processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }

    private function resolvePeriod(string $period): string
    {
        return match ($period) {
            'morning', 'open' => 'morning',
            'evening', 'close' => 'evening',
            default => (int) now()->format('G') < 14 ? 'morning' : 'evening',
        };
    }
}
