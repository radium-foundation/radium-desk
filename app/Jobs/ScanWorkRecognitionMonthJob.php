<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueRouting;
use App\Services\Workforce\Recognition\WorkRecognitionReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanWorkRecognitionMonthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $month,
    ) {
        $this->onQueue(QueueRouting::maintenance());
    }

    public function handle(WorkRecognitionReviewService $reviewService): void
    {
        if (! $reviewService->enabled()) {
            Log::info('workforce.recognition.scan.skipped', [
                'reason' => 'disabled',
                'month' => $this->month,
            ]);

            return;
        }

        $month = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $touched = $reviewService->scanMonth($month);

        Log::info('workforce.recognition.scan.completed', [
            'month' => $this->month,
            'touched' => $touched,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('workforce.recognition.scan.failed', [
            'month' => $this->month,
            'message' => $exception?->getMessage(),
        ]);
    }
}
