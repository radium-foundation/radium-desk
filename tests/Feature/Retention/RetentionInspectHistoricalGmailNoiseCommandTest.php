<?php

namespace Tests\Feature\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionInspectHistoricalGmailNoiseCommandTest extends TestCase
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
        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'command-candidate',
            'from_email' => 'promo@example.com',
            'subject' => 'Promo',
            'preview' => 'Promo body',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'promotions',
            'received_at' => '2026-04-01 09:00:00',
        ]);

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-inspect-historical-gmail-noise', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('read-only; zero database writes')
            ->expectsOutputToContain('received_at < 2026-07-01 00:00:00')
            ->expectsOutputToContain('created_at is not used')
            ->expectsOutputToContain('promotions')
            ->expectsOutputToContain('No rows were deleted or modified');

        $this->assertSame($before, IncomingEmailMessage::query()->count());
        $this->assertSame($before, (int) DB::table('incoming_email_messages')->count());
    }
}
