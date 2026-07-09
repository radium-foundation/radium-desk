<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\Bonvoice\BonvoiceWebhookAuthVerifier;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReprocessFailedBonvoiceWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.verify_signature' => false,
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('bonvoice:reprocess-failed --help')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_candidates_without_making_changes(): void
    {
        $log = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-07-08 10:00:00'),
        ]);

        $this->artisan('bonvoice:reprocess-failed --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run: 1 webhook log(s) would be reprocessed.')
            ->expectsOutputToContain(sprintf('Log #%d (would recover)', $log->id))
            ->expectsOutputToContain('Total logs found: 1')
            ->expectsOutputToContain('Would recover: 1')
            ->expectsOutputToContain('Skipped (already processed): 0')
            ->expectsOutputToContain('Still failed: 0')
            ->expectsOutputToContain('Execution time:');

        $this->assertSame(BonvoiceWebhookLog::STATUS_FAILED, $log->fresh()->processing_status);
        $this->assertSame(0, BonvoiceCallEvent::query()->count());
    }

    public function test_successful_replay_creates_call_event_and_marks_log_processed(): void
    {
        $log = $this->createFailedLog();

        $this->artisan('bonvoice:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Total logs found: 1')
            ->expectsOutputToContain('Successfully recovered: 1')
            ->expectsOutputToContain('Skipped (already processed): 0')
            ->expectsOutputToContain('Still failed: 0');

        $log = $log->fresh();
        $this->assertSame(BonvoiceWebhookLog::STATUS_PROCESSED, $log->processing_status);
        $this->assertNull($log->processing_error);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-replay-001',
            'leg' => 'A',
            'webhook_log_id' => $log->id,
        ]);
    }

    public function test_replay_skips_duplicate_call_event_from_another_log(): void
    {
        $existingLog = BonvoiceWebhookLog::query()->create([
            'event_type' => 'Support:Ringing',
            'payload' => $this->inboundCallPayload(),
            'raw_body' => json_encode($this->inboundCallPayload()),
            'request_headers' => [],
            'received_at' => Carbon::parse('2026-07-08 09:00:00'),
            'processing_status' => BonvoiceWebhookLog::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-replay-001',
            'leg' => 'A',
            'customer_phone' => '9876543210',
            'source_number' => '9876543210',
            'destination_number' => '1800123456',
            'direction' => 'Inbound',
            'status' => 'Ringing',
            'payload' => $this->inboundCallPayload(),
            'webhook_log_id' => $existingLog->id,
        ]);

        $failedLog = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-07-08 10:00:00'),
        ]);

        $this->artisan('bonvoice:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 0')
            ->expectsOutputToContain('Skipped (already processed): 1')
            ->expectsOutputToContain('Still failed: 0');

        $this->assertSame(BonvoiceWebhookLog::STATUS_FAILED, $failedLog->fresh()->processing_status);
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_replay_does_not_send_live_assist_notifications_by_default(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createFailedLog([
            'payload' => $this->inboundCallPayload(status: 'ANSWERED'),
            'processing_error' => BonvoiceWebhookAuthVerifier::ERROR_INVALID_AUTHORIZATION,
        ]);

        $this->artisan('bonvoice:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 1');

        Notification::assertNothingSent();
        $this->assertSame(0, BonvoiceCallAlert::query()->count());
    }

    public function test_replay_can_send_notifications_when_opted_in(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createFailedLog([
            'payload' => $this->inboundCallPayload(status: 'ANSWERED'),
            'processing_error' => BonvoiceWebhookAuthVerifier::ERROR_INVALID_AUTHORIZATION,
        ]);

        $this->artisan('bonvoice:reprocess-failed --with-notifications')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 1');

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class);
        $this->assertSame(1, BonvoiceCallAlert::query()->count());
    }

    public function test_logs_are_ordered_by_start_time_then_id(): void
    {
        $laterStart = $this->createFailedLog([
            'payload' => $this->inboundCallPayload(
                callId: 'call-later',
                startTime: '2026-07-08 12:00:00',
            ),
            'received_at' => Carbon::parse('2026-07-08 11:00:00'),
        ]);

        $earlierStart = $this->createFailedLog([
            'payload' => $this->inboundCallPayload(
                callId: 'call-earlier',
                startTime: '2026-07-08 10:00:00',
            ),
            'received_at' => Carbon::parse('2026-07-08 12:00:00'),
        ]);

        $this->artisan('bonvoice:reprocess-failed --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain(sprintf('Log #%d (would recover)', $earlierStart->id))
            ->expectsOutputToContain(sprintf('Log #%d (would recover)', $laterStart->id));

        $this->artisan('bonvoice:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 2');

        $this->assertSame(
            BonvoiceWebhookProcessorService::STATUS_PROCESSED,
            $earlierStart->fresh()->processing_status,
        );
        $this->assertSame(
            BonvoiceWebhookProcessorService::STATUS_PROCESSED,
            $laterStart->fresh()->processing_status,
        );
    }

    public function test_invalid_log_id_returns_failure(): void
    {
        $this->artisan('bonvoice:reprocess-failed --log=99999')
            ->assertFailed()
            ->expectsOutputToContain('Webhook log #99999 was not found.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createFailedLog(array $overrides = []): BonvoiceWebhookLog
    {
        $payload = $overrides['payload'] ?? $this->inboundCallPayload();

        return BonvoiceWebhookLog::query()->create(array_merge([
            'event_type' => 'Support:Ringing',
            'payload' => $payload,
            'raw_body' => json_encode($payload),
            'request_headers' => [],
            'received_at' => now(),
            'processing_status' => BonvoiceWebhookLog::STATUS_FAILED,
            'processing_error' => 'BonVoice webhook payload is missing callID.',
            'processed_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId = 'call-replay-001',
        string $status = 'Ringing',
        string $startTime = '2026-07-08 10:15:00',
    ): array {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => $startTime,
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => $status,
            'AgentStatus' => 'Idle',
            'eventID' => 'evt-replay-001',
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
