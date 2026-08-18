<?php

namespace Tests\Feature\Retention;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetentionPruneHistoricalGmailNoiseCommandTest extends TestCase
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

    public function test_default_command_performs_no_writes(): void
    {
        $this->seedCandidate();

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-prune-historical-gmail-noise')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run — no historical Gmail noise rows will be deleted.')
            ->expectsOutputToContain('Deleted this run')
            ->expectsOutputToContain('0')
            ->expectsOutputToContain('No rows were deleted');

        $this->assertSame($before, IncomingEmailMessage::query()->count());
    }

    public function test_dry_run_flag_performs_no_writes(): void
    {
        $this->seedCandidate();

        $before = IncomingEmailMessage::query()->count();

        $this->artisan('database:retention-prune-historical-gmail-noise', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, IncomingEmailMessage::query()->count());
    }

    public function test_execute_deletes_candidates(): void
    {
        $candidate = $this->seedCandidate();

        $this->artisan('database:retention-prune-historical-gmail-noise', ['--execute' => true, '--limit' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('EXECUTE mode');

        $this->assertNull(IncomingEmailMessage::query()->find($candidate->id));
    }

    public function test_execute_and_dry_run_together_are_rejected(): void
    {
        $this->artisan('database:retention-prune-historical-gmail-noise', [
            '--execute' => true,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('not both');
    }

    public function test_unknown_customer_remains_untouched_on_execute(): void
    {
        $this->seedCandidate(['ignore_reason' => 'unknown_customer', 'provider_message_id' => 'unknown-1']);
        $candidate = $this->seedCandidate(['ignore_reason' => 'spam', 'provider_message_id' => 'spam-1']);

        $this->artisan('database:retention-prune-historical-gmail-noise', ['--execute' => true, '--limit' => 5])
            ->assertSuccessful();

        $this->assertNull(IncomingEmailMessage::query()->find($candidate->id));
        $this->assertSame(1, IncomingEmailMessage::query()->where('ignore_reason', 'unknown_customer')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedCandidate(array $overrides = []): IncomingEmailMessage
    {
        return IncomingEmailMessage::query()->create(array_merge([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-'.uniqid(),
            'from_email' => 'noise@example.com',
            'subject' => 'Historical noise',
            'preview' => 'Preview text',
            'status' => IncomingEmailMessageStatus::Ignored,
            'ignore_reason' => 'promotions',
            'received_at' => '2026-05-01 10:00:00',
            'attachment_count' => 0,
        ], $overrides));
    }
}
