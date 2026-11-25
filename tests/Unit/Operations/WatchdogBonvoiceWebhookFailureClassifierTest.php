<?php

namespace Tests\Unit\Operations;

use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookPayloadParser;
use App\Services\Operations\WatchdogBonvoiceWebhookFailureClassifier;
use Tests\TestCase;

class WatchdogBonvoiceWebhookFailureClassifierTest extends TestCase
{
    private WatchdogBonvoiceWebhookFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new WatchdogBonvoiceWebhookFailureClassifier(new BonvoiceWebhookPayloadParser);
    }

    private function failedLog(array $payload, string $error = 'BonVoice webhook payload is missing callID.'): BonvoiceWebhookLog
    {
        $log = new BonvoiceWebhookLog;
        $log->processing_status = BonvoiceWebhookLog::STATUS_FAILED;
        $log->processing_error = $error;
        $log->payload = $payload;

        return $log;
    }

    public function test_downgrades_exact_no_channel_signature(): void
    {
        $log = $this->failedLog([
            'Status' => 'NO_CHANNEL',
            'callType' => '2',
            'callID' => null,
        ]);

        $this->assertTrue($this->classifier->isDowngradedNoChannelNoise($log));
    }

    public function test_does_not_downgrade_no_channel_with_call_id(): void
    {
        $log = $this->failedLog([
            'Status' => 'NO_CHANNEL',
            'callType' => '2',
            'callID' => '12345',
        ]);

        $this->assertFalse($this->classifier->isDowngradedNoChannelNoise($log));
    }

    public function test_does_not_downgrade_null_call_id_with_noanswer(): void
    {
        $log = $this->failedLog([
            'Status' => 'NOANSWER',
            'callType' => '2',
            'callID' => null,
        ]);

        $this->assertFalse($this->classifier->isDowngradedNoChannelNoise($log));
    }

    public function test_does_not_downgrade_unrelated_processing_error(): void
    {
        $log = $this->failedLog([
            'Status' => 'NO_CHANNEL',
            'callType' => '2',
            'callID' => null,
        ], 'SQLSTATE connection timeout');

        $this->assertFalse($this->classifier->isDowngradedNoChannelNoise($log));
    }

    public function test_fails_closed_on_empty_payload(): void
    {
        $log = $this->failedLog([]);

        $this->assertFalse($this->classifier->isDowngradedNoChannelNoise($log));
    }
}
