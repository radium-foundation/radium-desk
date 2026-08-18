<?php

namespace Tests\Feature\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionInspectHistoricalUnknownCustomerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_reports_candidates_without_writes(): void
    {
        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'command-unknown-customer',
            'from_email' => 'unknown@example.com',
            'subject' => 'Historical unknown customer',
            'preview' => 'Preview',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'unknown_customer',
            'received_at' => '2026-06-30 23:59:59',
            'attachment_count' => 0,
            'raw_payload' => ['fixture' => true],
        ]);

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-inspect-historical-unknown-customer', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('read-only; zero database writes')
            ->expectsOutputToContain('Predicate: status=ignored AND ignore_reason=unknown_customer AND received_at < 2026-07-01 00:00:00')
            ->expectsOutputToContain('created_at is not used')
            ->expectsOutputToContain('Separate policy from historical Gmail noise')
            ->expectsOutputToContain('needs_review + unknown_customer')
            ->expectsOutputToContain('No rows were deleted or modified');

        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertSame($before, (int) DB::table('incoming_email_messages')->count());
    }
}
