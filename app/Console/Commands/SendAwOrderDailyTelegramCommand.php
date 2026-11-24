<?php

namespace App\Console\Commands;

use App\Services\Operations\AwOrderDailyTelegramService;
use Illuminate\Console\Command;

class SendAwOrderDailyTelegramCommand extends Command
{
    protected $signature = 'operations:send-aw-order-daily-telegram';

    protected $description = 'Send previous-day AW- order count to superadmin Telegram recipients (feature-flagged)';

    public function handle(AwOrderDailyTelegramService $service): int
    {
        if (! (bool) config('operations_aw_orders.daily_telegram.enabled', false)) {
            $this->info('AW order daily Telegram is disabled (AW_ORDER_DAILY_TELEGRAM_ENABLED=false).');

            return self::SUCCESS;
        }

        $result = $service->sendPreviousDaySummary();
        $this->info(sprintf(
            'AW order daily Telegram report_date=%s count=%d sent=%d skipped=%d failed=%d',
            $result['report_date'],
            $result['count'],
            $result['sent'],
            $result['skipped'],
            $result['failed'],
        ));

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
