<?php

namespace Tests\Unit\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutboxEventStatus;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\IncomingEmailMessage;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use App\Services\Retention\RetentionInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspect_counts_retention_candidates_without_writing(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00');

        $user = User::factory()->create();

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.completed.old',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 1,
            'payload' => ['incoming_email_message_id' => 1],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(20),
            'processed_at' => now()->subDays(20),
        ]);

        OutboxEvent::query()->create([
            'idempotency_key' => 'retention.completed.recent',
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_type' => IncomingEmailOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 2,
            'payload' => ['incoming_email_message_id' => 2],
            'status' => OutboxEventStatus::Completed,
            'attempts' => 1,
            'available_at' => now()->subDays(5),
            'processed_at' => now()->subDays(5),
        ]);

        DB::table('cache')->insert([
            ['key' => 'expired-cache', 'value' => 'x', 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'fresh-cache', 'value' => 'y', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => 'cf-old',
            'request_payload' => ['type' => 'TEST'],
            'request_headers' => [],
            'raw_body' => '{}',
            'received_at' => now()->subDays(100),
            'processing_status' => 'processed',
            'processed_at' => now()->subDays(100),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) str()->uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => '{}',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => 'App\\Models\\Incident',
            'auditable_id' => 1,
            'old_values' => [],
            'new_values' => [],
        ])->forceFill(['created_at' => now()->subDays(400)])->save();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'incoming_email.received',
            'auditable_type' => IncomingEmailMessage::class,
            'auditable_id' => 99,
            'old_values' => [],
            'new_values' => [],
        ])->forceFill(['created_at' => now()->subDays(400)])->save();

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'ignored-old',
            'from_email' => 'spam@example.com',
            'subject' => 'Old spam',
            'preview' => 'Old spam',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'spam',
            'received_at' => now()->subDays(100),
        ])->forceFill(['created_at' => now()->subDays(100)])->save();

        $beforeCounts = [
            'outbox' => OutboxEvent::query()->count(),
            'cache' => (int) DB::table('cache')->count(),
            'webhooks' => CashfreeWebhookLog::query()->count(),
            'notifications' => (int) DB::table('notifications')->count(),
            'audit' => AuditLog::query()->count(),
            'email' => IncomingEmailMessage::query()->count(),
        ];

        $summary = app(RetentionInspectionService::class)->inspect(now());

        $this->assertSame(6, $summary->totalCandidates);

        $byKey = collect($summary->categories)->keyBy('key');

        $this->assertSame(1, $byKey['completed_outbox']->candidateCount);
        $this->assertSame(2, $byKey['completed_outbox']->tableTotalCount);
        $this->assertSame(1, $byKey['expired_cache']->candidateCount);
        $this->assertSame(2, $byKey['expired_cache']->tableTotalCount);
        $this->assertSame(1, $byKey['cashfree_webhook_logs']->candidateCount);
        $this->assertSame(1, $byKey['notifications']->candidateCount);
        $this->assertSame(1, $byKey['business_audit']->candidateCount);
        $this->assertSame(2, $byKey['business_audit']->tableTotalCount);
        $this->assertSame(1, $byKey['ignored_email']->candidateCount);

        $this->assertSame($beforeCounts['outbox'], OutboxEvent::query()->count());
        $this->assertSame($beforeCounts['cache'], (int) DB::table('cache')->count());
        $this->assertSame($beforeCounts['webhooks'], CashfreeWebhookLog::query()->count());
        $this->assertSame($beforeCounts['notifications'], (int) DB::table('notifications')->count());
        $this->assertSame($beforeCounts['audit'], AuditLog::query()->count());
        $this->assertSame($beforeCounts['email'], IncomingEmailMessage::query()->count());

        Carbon::setTestNow();
    }
}
