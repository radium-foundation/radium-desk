<?php

namespace App\CentralWallet\Infrastructure\Jobs;

use App\CentralWallet\Application\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ExpireActiveReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ReservationService $reservations): void
    {
        if (! config('central_wallet.enabled', false) || ! config('central_wallet.reservations.enabled', false)) {
            return;
        }

        $batchSize = max(1, (int) config('central_wallet.reservations.expiry_batch_size', 100));
        $expired = $reservations->expireDueReservations($batchSize);

        Log::channel((string) config('central_wallet.log_channel', 'stack'))->info('central_wallet.reservations.expired', [
            'count' => $expired,
        ]);
    }
}
