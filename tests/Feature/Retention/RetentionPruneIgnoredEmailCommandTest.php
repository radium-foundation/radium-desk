<?php

namespace Tests\Feature\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionPruneIgnoredEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_invocation_is_dry_run(): void
    {
        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-default-dry-run',
            'from_email' => 'noise@example.com',
            'subject' => 'Ignored retention command test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-prune-ignored-email')
            ->expectsOutputToContain('DRY-RUN — NO ROWS DELETED')
            ->assertSuccessful();

        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertSame($before, (int) DB::table('incoming_email_messages')->count());
    }

    public function test_dry_run_and_execute_flags_are_mutually_exclusive(): void
    {
        $this->artisan('database:retention-prune-ignored-email', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_manifest_option_writes_candidate_ids(): void
    {
        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-manifest',
            'from_email' => 'noise@example.com',
            'subject' => 'Manifest test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'own_outbound',
            'received_at' => '2026-06-01 10:00:00',
            'processed_at' => '2026-06-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $manifest = storage_path('app/testing/ignored-email-retention-manifest.txt');

        if (is_file($manifest)) {
            unlink($manifest);
        }

        $this->artisan('database:retention-prune-ignored-email', [
            '--manifest' => $manifest,
        ])->assertSuccessful();

        $this->assertFileExists($manifest);
        $this->assertSame(((string) $message->id).PHP_EOL, (string) file_get_contents($manifest));

        unlink($manifest);
    }
}
