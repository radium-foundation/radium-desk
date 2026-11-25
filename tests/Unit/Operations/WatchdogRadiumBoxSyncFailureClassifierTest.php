<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\WatchdogRadiumBoxSyncFailureClassifier;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WatchdogRadiumBoxSyncFailureClassifierTest extends TestCase
{
    private WatchdogRadiumBoxSyncFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ira.watchdog.actionable_hours' => 48,
            'ira.watchdog.stale_days' => 30,
        ]);
        $this->classifier = new WatchdogRadiumBoxSyncFailureClassifier;
    }

    public function test_includes_exactly_48_hours_in_actionable_window(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $lastSync = $at->copy()->subHours(48);

        $bucket = $this->classifier->bucketForRecency($lastSync, $at);

        $this->assertSame(WatchdogRadiumBoxSyncFailureClassifier::BUCKET_ACTIONABLE, $bucket);
    }

    public function test_just_beyond_48_hours_is_aging(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $lastSync = $at->copy()->subHours(48)->subSecond();

        $this->assertSame(
            WatchdogRadiumBoxSyncFailureClassifier::BUCKET_AGING,
            $this->classifier->bucketForRecency($lastSync, $at),
        );
    }

    public function test_older_than_30_days_is_debt(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $lastSync = $at->copy()->subDays(30)->subSecond();

        $this->assertSame(
            WatchdogRadiumBoxSyncFailureClassifier::BUCKET_DEBT,
            $this->classifier->bucketForRecency($lastSync, $at),
        );
    }

    public function test_future_timestamp_is_not_actionable(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $future = $at->copy()->addHour();

        $result = $this->classifier->classifyRow('RD123', $future, null, $at);

        $this->assertFalse($result['eligible_critical']);
    }

    public function test_cfpay_is_excluded_from_critical_even_when_recent(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $recent = $at->copy()->subHours(1);

        $result = $this->classifier->classifyRow('CFPay_techsupport_x', $recent, $recent, $at);

        $this->assertFalse($result['eligible_critical']);
        $this->assertSame(WatchdogRadiumBoxSyncFailureClassifier::BUCKET_EXCLUDED_PREFIX, $result['bucket']);
    }

    public function test_rd_and_rb_recent_are_eligible_critical(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $recent = $at->copy()->subHours(2);

        $this->assertTrue($this->classifier->classifyRow('RD500', $recent, $recent, $at)['eligible_critical']);
        $this->assertTrue($this->classifier->classifyRow('RB900', $recent, $recent, $at)['eligible_critical']);
    }

    public function test_created_at_fallback_only_when_last_sync_missing(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $created = $at->copy()->subHours(3);

        $this->assertTrue($this->classifier->classifyRow('RD1', null, $created, $at)['eligible_critical']);
    }

    public function test_old_last_sync_is_not_saved_by_recent_created_at(): void
    {
        $at = Carbon::parse('2026-11-25 12:00:00');
        $oldSync = $at->copy()->subDays(40);
        $recentCreated = $at->copy()->subHours(1);

        $this->assertFalse($this->classifier->classifyRow('RD1', $oldSync, $recentCreated, $at)['eligible_critical']);
    }
}
