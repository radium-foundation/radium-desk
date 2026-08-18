<?php

namespace Tests\Unit\Automation;

use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerWaitingLifecycleServiceTest extends TestCase
{
    public function test_auto_close_cutoff_is_same_day_when_followup_sent_before_business_cutoff(): void
    {
        $followupSentAt = Carbon::parse('2026-07-07 10:00:00', AppDateFormatter::timezone());

        $cutoffAt = CustomerWaitingLifecycleService::autoCloseCutoffAt($followupSentAt);

        $this->assertTrue($cutoffAt->equalTo(
            Carbon::parse('2026-07-07 18:00:00', AppDateFormatter::timezone()),
        ));
        $this->assertFalse(CustomerWaitingLifecycleService::isAutoCloseCutoffReached(
            $followupSentAt,
            Carbon::parse('2026-07-07 17:00:00', AppDateFormatter::timezone()),
        ));
        $this->assertTrue(CustomerWaitingLifecycleService::isAutoCloseCutoffReached(
            $followupSentAt,
            Carbon::parse('2026-07-07 18:00:00', AppDateFormatter::timezone()),
        ));
    }

    public function test_auto_close_cutoff_is_next_day_when_followup_sent_after_business_cutoff(): void
    {
        $followupSentAt = Carbon::parse('2026-07-07 20:00:00', AppDateFormatter::timezone());

        $cutoffAt = CustomerWaitingLifecycleService::autoCloseCutoffAt($followupSentAt);

        $this->assertTrue($cutoffAt->equalTo(
            Carbon::parse('2026-07-08 18:00:00', AppDateFormatter::timezone()),
        ));
        $this->assertFalse(CustomerWaitingLifecycleService::isAutoCloseCutoffReached(
            $followupSentAt,
            Carbon::parse('2026-07-07 21:00:00', AppDateFormatter::timezone()),
        ));
        $this->assertTrue(CustomerWaitingLifecycleService::isAutoCloseCutoffReached(
            $followupSentAt,
            Carbon::parse('2026-07-08 18:00:00', AppDateFormatter::timezone()),
        ));
    }

    public function test_audit_event_constants_fit_audit_logs_event_column(): void
    {
        $events = [
            CustomerWaitingLifecycleService::EVENT_WAITING_STARTED,
            CustomerWaitingLifecycleService::EVENT_IDENTITY_RESOLVED,
            CustomerWaitingLifecycleService::EVENT_AUTO_CLOSED,
            CustomerWaitingLifecycleService::EVENT_ALREADY_CLOSED_WAITING_CLEARED,
            CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED,
        ];

        foreach ($events as $event) {
            $this->assertLessThanOrEqual(50, strlen($event), $event);
        }
    }
}
