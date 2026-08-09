<?php

namespace App\Console\Commands;

use App\Data\CashfreeMissedBatchHealOrderResult;
use App\Enums\CashfreeMissedBatchHealDisposition;
use App\Services\Cashfree\CashfreeMissedWebhookHealService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('cashfree:heal-missed-batch
    {--dry-run : Preview intended synthetic webhooks without writing (default when --execute is omitted)}
    {--execute : Insert synthetic webhook logs and process via CashfreeWebhookProcessorService}
    {--order=* : Limit to specific allowlisted order ID(s)}')]
#[Description('One-time heal for the Aug 7 Cashfree missed-webhook batch via synthetic PAYMENT_SUCCESS logs')]
class HealMissedCashfreeBatchCommand extends Command
{
    public function __construct(
        private readonly CashfreeMissedWebhookHealService $healService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRunFlag = (bool) $this->option('dry-run');

        if ($execute && $dryRunFlag) {
            $this->error('Pass either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        $dryRun = ! $execute;
        $orderOptions = $this->option('order');
        $orderIds = is_array($orderOptions)
            ? array_values(array_filter(array_map('strval', $orderOptions)))
            : [];

        if ($dryRun) {
            $this->info('Dry run — no webhook logs or orders will be written.');
        } else {
            $this->warn('EXECUTE mode — synthetic cashfree_webhook_logs will be inserted and processed.');
        }

        try {
            $result = $this->healService->heal(
                orderIds: $orderIds === [] ? null : $orderIds,
                dryRun: $dryRun,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Heal failed: '.$exception->getMessage());

            Log::error('[Cashfree Missed Batch Heal] Command failed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($result->orders as $orderResult) {
            $this->renderOrderResult($orderResult, $dryRun);
        }

        $this->newLine();
        $this->line('Targets: '.count($result->orders));
        $this->line('Would heal: '.$result->wouldHeal);
        $this->line('Healed: '.$result->healed);
        $this->line('Resumed: '.$result->resumed);
        $this->line('Skipped: '.$result->skipped);
        $this->line('Blocked: '.$result->blocked);
        $this->line('Failed: '.$result->failed);

        Log::info('[Cashfree Missed Batch Heal] Command completed.', [
            'dry_run' => $dryRun,
            'targets' => count($result->orders),
            'would_heal' => $result->wouldHeal,
            'healed' => $result->healed,
            'resumed' => $result->resumed,
            'skipped' => $result->skipped,
            'blocked' => $result->blocked,
            'failed' => $result->failed,
        ]);

        if ($result->failed > 0 || $result->blocked > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderOrderResult(CashfreeMissedBatchHealOrderResult $result, bool $dryRun): void
    {
        $this->line(sprintf(
            '[%s] %s | cf_payment_id=%s | reason=%s',
            strtoupper($result->disposition->value),
            $result->orderId,
            $result->cfPaymentId ?? 'n/a',
            $result->reason,
        ));

        if (
            $result->payload !== null
            && (
                $result->disposition === CashfreeMissedBatchHealDisposition::WouldHeal
                || $result->disposition === CashfreeMissedBatchHealDisposition::Healed
                || $result->disposition === CashfreeMissedBatchHealDisposition::Resumed
            )
        ) {
            $this->line('  intended_payload='.json_encode(
                $this->redactPayloadForDisplay($result->payload),
                JSON_UNESCAPED_SLASHES,
            ));
        }

        if ($result->expectedSerial !== null) {
            $this->line('  cashfree_serial_tag='.$result->expectedSerial);
        }

        if ($result->webhookLogId !== null && ! $dryRun) {
            $this->line('  webhook_log_id='.$result->webhookLogId);
        }

        if ($result->deskOrderId !== null) {
            $this->line('  desk_order_db_id='.$result->deskOrderId);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayloadForDisplay(array $payload): array
    {
        // Webhook payload contains no API secrets; keep structure intact for operator review.
        return $payload;
    }
}
