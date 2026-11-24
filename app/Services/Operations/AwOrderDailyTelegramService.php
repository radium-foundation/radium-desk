<?php

namespace App\Services\Operations;

use App\Data\Telegram\TelegramSendResult;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Superadmin-only previous-day AW- order count Telegram digest.
 *
 * Delivery policy: at-least-once (not exactly-once). See docs/operations-aw-order-daily-telegram.md.
 */
final class AwOrderDailyTelegramService
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly AwOrderDailyCountService $countService,
        private readonly SuperAdminTelegramRecipientService $recipientService,
        private readonly AwOrderDailyTelegramDeliveryGate $deliveryGate,
        private readonly TelegramBotService $telegramBot,
    ) {}

    /**
     * @return array{sent: int, skipped: int, failed: int, report_date: string, count: int}
     */
    public function sendPreviousDaySummary(?Carbon $asOf = null): array
    {
        if (! (bool) config('operations_aw_orders.daily_telegram.enabled', false)) {
            Log::info('AW order daily Telegram: feature disabled.');

            return [
                'sent' => 0,
                'skipped' => 0,
                'failed' => 0,
                'report_date' => '',
                'count' => 0,
            ];
        }

        $reportDate = $this->countService->previousReportDate($asOf);
        $reportDateKey = $reportDate->toDateString();
        $count = $this->countService->countForReportDate($reportDate);
        $message = $this->countService->formatSummaryMessage($reportDate, $count);

        $recipients = $this->recipientService->eligibleSuperAdminTelegramRecipients();
        if ($recipients->isEmpty()) {
            Log::warning('AW order daily Telegram: no eligible superadmin Telegram recipients.', [
                'report_date' => $reportDateKey,
            ]);

            return [
                'sent' => 0,
                'skipped' => 0,
                'failed' => 0,
                'report_date' => $reportDateKey,
                'count' => $count,
            ];
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $outcome = $this->deliverToRecipient($recipient, $reportDateKey, $message);
            match ($outcome) {
                'sent' => $sent++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'report_date' => $reportDateKey,
            'count' => $count,
        ];
    }

    /**
     * @return 'sent'|'skipped'|'failed'
     */
    private function deliverToRecipient(User $recipient, string $reportDateKey, string $message): string
    {
        $lock = Cache::lock(
            $this->lockKey($reportDateKey, $recipient->id),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            Log::warning('AW order daily Telegram: lock contention; retryable failure.', [
                'report_date' => $reportDateKey,
                'user_id' => $recipient->id,
            ]);

            return 'failed';
        }

        try {
            if (! $this->recipientService->assertEligibleSuperAdminTelegramRecipient($recipient)) {
                return 'skipped';
            }

            if ($this->deliveryGate->hasDeliveredReceipt($reportDateKey, $recipient->id)) {
                return 'skipped';
            }

            $chatId = (string) $recipient->fresh()->telegram_chat_id;
            if ($chatId === '') {
                return 'skipped';
            }

            $result = $this->telegramBot->sendMessage($chatId, $message);

            return $this->handleTelegramResult($result, $reportDateKey, $recipient->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return 'sent'|'skipped'|'failed'
     */
    private function handleTelegramResult(
        TelegramSendResult $result,
        string $reportDateKey,
        int $userId,
    ): string {
        if ($result->skipped) {
            Log::warning('AW order daily Telegram: Telegram send skipped.', [
                'report_date' => $reportDateKey,
                'user_id' => $userId,
                'reason' => $result->error,
            ]);

            return 'failed';
        }

        if (! $result->success) {
            Log::warning('AW order daily Telegram: Telegram API failure (no receipt recorded).', [
                'report_date' => $reportDateKey,
                'user_id' => $userId,
                'error' => $result->error,
            ]);

            return 'failed';
        }

        try {
            $this->deliveryGate->recordSuccessfulDelivery(
                $reportDateKey,
                $userId,
                $result->messageId,
            );
        } catch (\Throwable $exception) {
            Log::error('AW order daily Telegram: delivery receipt insert failed after Telegram success.', [
                'report_date' => $reportDateKey,
                'user_id' => $userId,
                'exception' => $exception->getMessage(),
            ]);

            return 'failed';
        }

        return 'sent';
    }

    private function lockKey(string $reportDateKey, int $userId): string
    {
        return "operations:aw-order-daily:{$reportDateKey}:user:{$userId}";
    }
}
