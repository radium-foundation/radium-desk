<?php

namespace App\Services\Operations;

use App\Models\OperationalTelegramDeliveryReceipt;
use Illuminate\Support\Carbon;

/**
 * Durable per-report-date / per-superadmin delivery receipts (written only after Telegram success).
 */
final class AwOrderDailyTelegramDeliveryGate
{
    public function hasDeliveredReceipt(string $reportDate, int $userId): bool
    {
        return OperationalTelegramDeliveryReceipt::query()
            ->where('delivery_kind', OperationalTelegramDeliveryReceipt::KIND_AW_ORDER_DAILY)
            ->whereDate('report_date', $reportDate)
            ->where('user_id', $userId)
            ->exists();
    }

    public function recordSuccessfulDelivery(
        string $reportDate,
        int $userId,
        ?string $telegramMessageId,
        ?Carbon $deliveredAt = null,
    ): void {
        OperationalTelegramDeliveryReceipt::query()->create([
            'delivery_kind' => OperationalTelegramDeliveryReceipt::KIND_AW_ORDER_DAILY,
            'report_date' => $reportDate,
            'user_id' => $userId,
            'telegram_message_id' => $telegramMessageId,
            'delivered_at' => $deliveredAt ?? now(),
        ]);
    }
}
