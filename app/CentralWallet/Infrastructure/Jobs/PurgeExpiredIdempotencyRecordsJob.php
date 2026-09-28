<?php

namespace App\CentralWallet\Infrastructure\Jobs;

use App\CentralWallet\Application\IdempotencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PurgeExpiredIdempotencyRecordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(IdempotencyService $idempotency): void
    {
        if (! config('central_wallet.enabled', false)) {
            return;
        }

        $purged = $idempotency->purgeExpired();

        Log::channel((string) config('central_wallet.log_channel', 'stack'))->info('central_wallet.idempotency.purged', [
            'count' => $purged,
        ]);
    }
}
