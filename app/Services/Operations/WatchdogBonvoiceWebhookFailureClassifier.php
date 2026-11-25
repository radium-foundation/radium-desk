<?php

namespace App\Services\Operations;

use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookPayloadParser;

/**
 * Downgrades only the verified NO_CHANNEL + missing callID signature from Critical counts.
 */
final class WatchdogBonvoiceWebhookFailureClassifier
{
    private const MISSING_CALL_ID_ERROR = 'BonVoice webhook payload is missing callID.';

    public function __construct(
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
    ) {}

    public function isDowngradedNoChannelNoise(BonvoiceWebhookLog $log): bool
    {
        if ($log->processing_status !== BonvoiceWebhookLog::STATUS_FAILED) {
            return false;
        }

        $error = trim((string) ($log->processing_error ?? ''));
        if (! str_contains($error, self::MISSING_CALL_ID_ERROR)) {
            return false;
        }

        $payload = is_array($log->payload) ? $log->payload : [];

        if ($payload === []) {
            return false;
        }

        $status = strtoupper((string) ($this->payloadParser->status($payload) ?? ''));
        if ($status !== 'NO_CHANNEL') {
            return false;
        }

        $callType = (string) ($this->payloadParser->callType($payload) ?? '');
        if ($callType !== '2') {
            return false;
        }

        if ($this->payloadParser->hasRequiredIdentifiers($payload)) {
            return false;
        }

        return true;
    }

    public function countsForRecentFailures(iterable $logs): array
    {
        $legacy = 0;
        $critical = 0;
        $downgraded = 0;

        foreach ($logs as $log) {
            if (! $log instanceof BonvoiceWebhookLog) {
                continue;
            }

            $legacy++;

            if ($this->isDowngradedNoChannelNoise($log)) {
                $downgraded++;
            } else {
                $critical++;
            }
        }

        return [
            'legacy_count' => $legacy,
            'critical_count' => $critical,
            'downgraded_count' => $downgraded,
        ];
    }
}
