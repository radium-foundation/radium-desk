<?php

namespace Tests\Unit\Operations;

use App\Data\Operations\ProductionCriticalAlert;
use App\Services\Operations\WatchdogCriticalAlertGate;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WatchdogCriticalAlertGateTest extends TestCase
{
    private WatchdogCriticalAlertGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        WatchdogCriticalAlertGate::clearDurableForTests();
        Cache::flush();
        $this->gate = new WatchdogCriticalAlertGate;
    }

    protected function tearDown(): void
    {
        WatchdogCriticalAlertGate::clearDurableForTests();
        Cache::flush();

        parent::tearDown();
    }

    public function test_first_alert_sends(): void
    {
        $alert = $this->queueAlert(['aaa-111']);

        $this->assertTrue($this->gate->shouldNotify($alert));
    }

    public function test_identical_alert_suppresses(): void
    {
        $alert = $this->queueAlert(['aaa-111', 'bbb-222']);

        $this->gate->markNotified($alert);

        $this->assertFalse($this->gate->shouldNotify($alert));
        $this->assertFalse($this->gate->shouldNotify($this->queueAlert(['bbb-222', 'aaa-111'])));
    }

    public function test_changed_uuid_set_sends(): void
    {
        $this->gate->markNotified($this->queueAlert(['aaa-111', 'bbb-222']));

        $this->assertTrue($this->gate->shouldNotify($this->queueAlert(['aaa-111', 'bbb-222', 'ccc-333'])));
    }

    public function test_severity_increase_sends(): void
    {
        $first = new ProductionCriticalAlert(
            key: 'cashfree:paid_missing_order',
            label: 'Cashfree',
            message: '1 paid payment(s) have no matching Desk order.',
            affectedCount: 1,
        );
        $worse = new ProductionCriticalAlert(
            key: 'cashfree:paid_missing_order',
            label: 'Cashfree',
            message: '1 paid payment(s) have no matching Desk order.',
            affectedCount: 2,
        );

        $this->gate->markNotified($first);

        $this->assertTrue($this->gate->shouldNotify($worse));
    }

    public function test_resolved_state_clears(): void
    {
        $alert = $this->queueAlert(['aaa-111']);
        $this->gate->markNotified($alert);
        $this->assertFalse($this->gate->shouldNotify($alert));

        $this->gate->syncResolved([]);

        $this->assertTrue($this->gate->shouldNotify($alert));
    }

    public function test_cache_flush_does_not_reset_durable_state(): void
    {
        $alert = $this->queueAlert(['aaa-111', 'bbb-222']);
        $this->gate->markNotified($alert);

        Cache::flush();

        $this->assertFalse($this->gate->shouldNotify($alert));
    }

    public function test_durable_test_state_can_be_explicitly_cleared(): void
    {
        $alert = $this->queueAlert(['aaa-111']);
        $this->gate->markNotified($alert);
        Cache::flush();
        $this->assertFalse($this->gate->shouldNotify($alert));

        WatchdogCriticalAlertGate::clearDurableForTests();
        Cache::flush();

        $this->assertTrue($this->gate->shouldNotify($alert));
    }

    public function test_incident_identity_ignores_message_and_affected_count(): void
    {
        $first = new ProductionCriticalAlert(
            key: 'queue:dead_letter',
            label: 'Queue',
            message: 'Queue worker (dedicated_cron): 2 failed job(s) in the dead-letter queue.',
            affectedCount: 2,
            incidentIdentity: 'aaa-111,bbb-222',
        );
        $wordingChanged = new ProductionCriticalAlert(
            key: 'queue:dead_letter',
            label: 'Queue',
            message: 'Queue worker (dedicated_cron): 2 failed job(s) in dead-letter queue.',
            affectedCount: 0,
            incidentIdentity: 'aaa-111,bbb-222',
        );

        $this->assertSame($first->fingerprint(), $wordingChanged->fingerprint());

        $this->gate->markNotified($first);

        $this->assertFalse($this->gate->shouldNotify($wordingChanged));
    }

    public function test_alerts_without_incident_identity_keep_message_fingerprint(): void
    {
        $first = new ProductionCriticalAlert(
            key: 'automation:failures',
            label: 'Automation',
            message: '2 open automation failure(s) require attention.',
            affectedCount: 2,
        );
        $differentMessage = new ProductionCriticalAlert(
            key: 'automation:failures',
            label: 'Automation',
            message: '3 open automation failure(s) require attention.',
            affectedCount: 2,
        );

        $this->assertNotSame($first->fingerprint(), $differentMessage->fingerprint());

        $this->gate->markNotified($first);

        $this->assertTrue($this->gate->shouldNotify($differentMessage));
    }

    /**
     * @param  list<string>  $uuids
     */
    private function queueAlert(array $uuids): ProductionCriticalAlert
    {
        $sorted = $uuids;
        sort($sorted);

        return new ProductionCriticalAlert(
            key: 'queue:dead_letter',
            label: 'Queue',
            message: 'Queue worker (dedicated_cron): '.count($sorted).' failed job(s) in the dead-letter queue.',
            affectedCount: count($sorted),
            incidentIdentity: implode(',', $sorted),
        );
    }
}
