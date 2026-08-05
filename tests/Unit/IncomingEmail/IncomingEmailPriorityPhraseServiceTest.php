<?php

namespace Tests\Unit\IncomingEmail;

use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailPriorityPhraseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailPriorityPhraseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_matches_configured_phrase_case_insensitively(): void
    {
        config(['inbound_email.priority_phrases' => ['Legal Notice', 'RBI complaint']]);

        $message = IncomingEmailMessage::query()->make([
            'subject' => 'Re: LEGAL NOTICE from counsel',
            'from_email' => 'lawyer@example.com',
            'preview' => 'Please respond urgently.',
        ]);

        $match = app(IncomingEmailPriorityPhraseService::class)->match($message);

        $this->assertNotNull($match);
        $this->assertSame('Legal Notice', $match->matchedPhrase);
    }

    public function test_returns_null_when_no_phrases_configured(): void
    {
        config(['inbound_email.priority_phrases' => []]);

        $message = IncomingEmailMessage::query()->make([
            'subject' => 'Legal notice',
            'preview' => 'Urgent',
        ]);

        $this->assertNull(app(IncomingEmailPriorityPhraseService::class)->match($message));
    }

    public function test_match_and_audit_writes_once_and_match_is_read_only(): void
    {
        config(['inbound_email.priority_phrases' => ['legal notice']]);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'priority-unit-1',
            'from_email' => 'lawyer@example.com',
            'subject' => 'Legal notice',
            'preview' => 'Urgent',
            'status' => 'needs_review',
            'received_at' => now(),
        ]);

        $service = app(IncomingEmailPriorityPhraseService::class);

        $this->assertNotNull($service->match($message));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'incoming_email.priority_detected',
            'auditable_id' => $message->id,
        ]);

        $this->assertNotNull($service->matchAndAudit($message));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.priority_detected',
            'auditable_id' => $message->id,
        ]);

        $this->assertNotNull($service->matchAndAudit($message));
        $this->assertSame(
            1,
            \App\Models\AuditLog::query()
                ->where('event', 'incoming_email.priority_detected')
                ->where('auditable_id', $message->id)
                ->count(),
        );
    }
}
