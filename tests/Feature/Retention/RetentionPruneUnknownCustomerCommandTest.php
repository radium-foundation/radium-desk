<?php

namespace Tests\Feature\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionPruneUnknownCustomerCommandTest extends TestCase
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
            'provider_message_id' => 'msg-default-dry-run-unknown',
            'from_email' => 'unknown@example.com',
            'subject' => 'Unknown customer retention command test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-08-01 10:00:00',
            'processed_at' => '2026-08-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-prune-unknown-customer', [
            '--no-manifest' => true,
        ])
            ->expectsOutputToContain('DRY-RUN — NO ROWS DELETED')
            ->assertSuccessful();

        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertSame($before, (int) DB::table('incoming_email_messages')->count());
    }

    public function test_dry_run_and_execute_flags_are_mutually_exclusive(): void
    {
        $this->artisan('database:retention-prune-unknown-customer', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_manifest_option_writes_candidate_ids_and_sha256(): void
    {
        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-unknown-manifest',
            'from_email' => 'unknown@example.com',
            'subject' => 'Manifest test',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-08-01 10:00:00',
            'processed_at' => '2026-08-01 10:05:00',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $manifest = storage_path('app/testing/unknown-customer-retention-manifest.txt');

        if (is_file($manifest)) {
            unlink($manifest);
        }

        $this->artisan('database:retention-prune-unknown-customer', [
            '--manifest' => $manifest,
        ])
            ->expectsOutputToContain('Manifest SHA256:')
            ->assertSuccessful();

        $this->assertFileExists($manifest);
        $this->assertSame(((string) $message->id).PHP_EOL, file_get_contents($manifest));
        $this->assertSame(hash_file('sha256', $manifest), hash('sha256', (string) $message->id.PHP_EOL));

        unlink($manifest);
    }
}
