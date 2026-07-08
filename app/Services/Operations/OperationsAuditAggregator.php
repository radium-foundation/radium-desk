<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OperationsAuditAggregator
{
    /** @var array<string, mixed>|null */
    private ?array $metrics = null;

    /** @var array<string, array{sent: int, failed: int, last_success_at: ?Carbon}> */
    private array $channelSummaries = [];

    /** @var array<string, int> */
    private array $channelFailureCounts = [];

    private ?int $dispatchesWithChannelFailuresCount = null;

    private ?int $totalDispatchCount = null;

    public function __construct(
        /** @var Collection<int, AuditLog> */
        private readonly Collection $logs,
        ?int $totalDispatchCount = null,
    ) {
        $this->totalDispatchCount = $totalDispatchCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        if ($this->metrics !== null) {
            return $this->metrics;
        }

        if ($this->logs->isEmpty()) {
            return $this->metrics = $this->emptyMetrics();
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $durations = [];
        $channelTotals = [
            'whatsapp' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'email' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'desktop' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
            'telegram' => ['sent' => 0, 'failed' => 0, 'skipped' => 0],
        ];

        foreach ($this->logs as $log) {
            $aggregateSuccess = (bool) ($log->new_values['aggregate_success'] ?? false);
            $channelResults = $log->new_values['channel_results'] ?? [];

            if (! is_array($channelResults)) {
                continue;
            }

            $hadSent = false;
            $hadFailed = false;
            $hadSkipped = false;

            foreach ($channelResults as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $channel = (string) ($result['channel'] ?? 'unknown');
                $status = (string) ($result['status'] ?? '');
                $success = (bool) ($result['success'] ?? false);

                if (! isset($channelTotals[$channel])) {
                    $channelTotals[$channel] = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
                }

                if ($status === 'not_yet_configured' || $this->isSkippedResult($result)) {
                    $channelTotals[$channel]['skipped']++;
                    $hadSkipped = true;

                    continue;
                }

                if ($success) {
                    $channelTotals[$channel]['sent']++;
                    $hadSent = true;
                } else {
                    $channelTotals[$channel]['failed']++;
                    $hadFailed = true;
                }

                $durationMs = (int) ($result['duration_ms'] ?? 0);

                if ($durationMs > 0) {
                    $durations[] = $durationMs;
                }
            }

            if ($hadFailed && ! $aggregateSuccess) {
                $failed++;
            } elseif ($hadSent) {
                $sent++;
            } elseif ($hadSkipped) {
                $skipped++;
            }
        }

        $attempted = $sent + $failed;
        $successRate = $attempted > 0 ? round(($sent / $attempted) * 100, 1) : null;
        $averageDeliveryMs = $durations !== [] ? (int) round(array_sum($durations) / count($durations)) : null;

        return $this->metrics = [
            'sent_today' => $sent,
            'failed_today' => $failed,
            'skipped_today' => $skipped,
            'channel_totals' => $channelTotals,
            'success_rate' => $successRate,
            'average_delivery_ms' => $averageDeliveryMs,
        ];
    }

    public function todayDispatchCount(): int
    {
        return $this->totalDispatchCount ?? $this->logs->count();
    }

    public function dispatchesWithChannelFailuresCount(): int
    {
        if ($this->dispatchesWithChannelFailuresCount !== null) {
            return $this->dispatchesWithChannelFailuresCount;
        }

        return $this->dispatchesWithChannelFailuresCount = $this->logs
            ->filter(fn (AuditLog $log): bool => $this->dispatchHadChannelFailure($log))
            ->count();
    }

    public function channelFailuresToday(string $channel): int
    {
        if (isset($this->channelFailureCounts[$channel])) {
            return $this->channelFailureCounts[$channel];
        }

        return $this->channelFailureCounts[$channel] = $this->logs
            ->filter(function (AuditLog $log) use ($channel): bool {
                foreach ($this->channelResults($log) as $result) {
                    if (($result['channel'] ?? null) === $channel
                        && ($result['success'] ?? false) === false
                        && ($result['status'] ?? '') !== 'not_yet_configured') {
                        return true;
                    }
                }

                return false;
            })
            ->count();
    }

    /**
     * @return array{sent: int, failed: int, last_success_at: ?Carbon}
     */
    public function channelSummary(string $channel): array
    {
        if (isset($this->channelSummaries[$channel])) {
            return $this->channelSummaries[$channel];
        }

        $sent = 0;
        $failed = 0;
        $lastSuccessAt = null;

        foreach ($this->logs->sortByDesc('created_at') as $log) {
            foreach ($this->channelResults($log) as $result) {
                if (! is_array($result) || ($result['channel'] ?? null) !== $channel) {
                    continue;
                }

                if (($result['success'] ?? false) === true && ($result['status'] ?? '') !== 'not_yet_configured') {
                    $sent++;
                    $lastSuccessAt ??= $log->created_at;
                } elseif (($result['status'] ?? '') !== 'not_yet_configured') {
                    $failed++;
                }
            }
        }

        return $this->channelSummaries[$channel] = [
            'sent' => $sent,
            'failed' => $failed,
            'last_success_at' => $lastSuccessAt,
        ];
    }

    public function dispatchHadChannelFailure(AuditLog $log): bool
    {
        foreach ($this->channelResults($log) as $result) {
            if (($result['success'] ?? false) === false && ($result['status'] ?? '') !== 'not_yet_configured') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelResults(AuditLog $log): array
    {
        $results = $log->new_values['channel_results'] ?? [];

        return is_array($results) ? $results : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(): array
    {
        return [
            'sent_today' => 0,
            'failed_today' => 0,
            'skipped_today' => 0,
            'channel_totals' => [],
            'success_rate' => null,
            'average_delivery_ms' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isSkippedResult(array $result): bool
    {
        $message = strtolower(trim((string) ($result['message'] ?? '')));

        return str_contains($message, 'not configured') || str_contains($message, 'disabled');
    }
}
