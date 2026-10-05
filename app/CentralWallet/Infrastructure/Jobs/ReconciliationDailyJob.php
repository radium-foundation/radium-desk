<?php

namespace App\CentralWallet\Infrastructure\Jobs;

use App\CentralWallet\Application\WalletRefundReconciliationService;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReconciliationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ReconciliationDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WalletRefundReconciliationService $walletRefundReconciliation): void
    {
        if (! config('central_wallet.reconciliation.enabled', false)) {
            return;
        }

        if (config('central_wallet.reconciliation.wallet_refund_detection_enabled', true)) {
            $run = $walletRefundReconciliation->runDailyDetection();

            Log::channel((string) config('central_wallet.log_channel', 'stack'))->info('central_wallet.reconciliation.daily.completed', [
                'run_id' => $run->id,
                'scope' => $run->scope,
                'summary' => $run->summary,
            ]);

            return;
        }

        $run = CentralWalletReconciliationRun::query()->create([
            'id' => (string) Str::uuid(),
            'scope' => 'daily_full',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'summary' => [
                'phase' => 1,
                'mode' => 'scaffold_only',
                'items_processed' => 0,
                'variances' => 0,
            ],
        ]);

        Log::channel((string) config('central_wallet.log_channel', 'stack'))->info('central_wallet.reconciliation.daily.completed', [
            'run_id' => $run->id,
            'scope' => $run->scope,
        ]);
    }
}
