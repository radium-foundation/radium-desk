<?php

namespace App\Console\Commands;

use App\Services\Cashfree\CashfreeMissingOrderAutoRecoveryService;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cashfree:auto-recover-missing
    {--dry-run : List recoverable missing paid orders without replaying webhooks}
    {--limit= : Max recoverable payments to process this run}')]
#[Description('Automatically recover Cashfree SUCCESS payments that are missing Desk orders')]
class AutoRecoverMissingCashfreeOrdersCommand extends Command
{
    public function __construct(
        private readonly CashfreeMissingOrderAutoRecoveryService $autoRecoveryService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) config('cashfree.auto_recover.enabled', true) && ! $this->option('dry-run')) {
            $this->warn('Cashfree auto-recovery is disabled (cashfree.auto_recover.enabled=false).');

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        if ($this->option('dry-run')) {
            $recoverable = $this->autoRecoveryService->previewRecoverableCandidates($limit);

            $this->info('Dry run — no webhook logs will be replayed.');
            $this->line('Recoverable missing paid orders: '.$recoverable->count());

            foreach ($recoverable as $candidate) {
                $log = $candidate['log'];
                $payload = $log->request_payload ?? [];

                $this->line(sprintf(
                    '- log #%d | order_id=%s | cf_payment_id=%s | paid_at=%s',
                    $log->id,
                    $this->payloadParser->orderId($payload) ?? 'unknown',
                    $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id ?? 'unknown',
                    $log->received_at?->toDateTimeString() ?? 'unknown',
                ));
            }

            return self::SUCCESS;
        }

        $result = $this->autoRecoveryService->run($limit);

        $this->line('Found: '.$result->found);
        $this->line('Recovered: '.$result->recovered);
        $this->line('Still failed: '.$result->stillFailed);

        return $result->stillFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
