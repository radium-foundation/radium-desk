<?php

namespace Tests\Unit\Bonvoice;

use App\Support\BonvoiceCallStatuses;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BonvoiceCallStatusesTest extends TestCase
{
    #[DataProvider('ringingCallProvider')]
    public function test_ringing_uses_call_type_without_status(
        ?string $status,
        ?string $callType,
        bool $expected,
    ): void {
        $this->assertSame($expected, BonvoiceCallStatuses::isRingingCall($status, $callType));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: bool}>
     */
    public static function ringingCallProvider(): array
    {
        return [
            'callType 0.5 no status' => [null, '0.5', true],
            'callType 0.5 without agent status needed' => [null, '0.5', true],
            'legacy STATUS RINGING' => ['RINGING', 'Support', true],
            'hangup is never ringing' => ['NOANSWER', '2', false],
            'hangup answered is not ringing' => ['ANSWERED', '2', false],
            'initiated is not ringing' => [null, '0', false],
            'answered callType is not ringing' => [null, '1', false],
        ];
    }

    public function test_answered_uses_call_type_one(): void
    {
        $this->assertTrue(BonvoiceCallStatuses::isAnsweredCall(null, '1'));
        $this->assertTrue(BonvoiceCallStatuses::isAnsweredCall('ANSWERED', '2'));
        $this->assertFalse(BonvoiceCallStatuses::isAnsweredCall('NOANSWER', '2'));
        $this->assertFalse(BonvoiceCallStatuses::isAnsweredCall(null, '0.5'));
    }

    public function test_live_assist_eligible_for_ringing_call_type(): void
    {
        $this->assertTrue(BonvoiceCallStatuses::isLiveAssistEligibleCall(null, '0.5'));
        $this->assertTrue(BonvoiceCallStatuses::isLiveAssistEligibleCall(null, '1'));
        $this->assertFalse(BonvoiceCallStatuses::isLiveAssistEligibleCall('NOANSWER', '2'));
        $this->assertTrue(BonvoiceCallStatuses::isLiveAssistEligibleCall('ANSWERED', '2'));
    }

    public function test_hangup_missed_statuses_include_bonvoice_outcomes(): void
    {
        foreach (['NOANSWER', 'BUSY', 'CANCEL', 'CHANUNAVAIL', 'CONGESTION', 'NOINPUT'] as $status) {
            $this->assertTrue(BonvoiceCallStatuses::isMissedStatus($status), $status);
        }

        $this->assertTrue(BonvoiceCallStatuses::isMissedStatus('CANCELLED'));
        $this->assertFalse(BonvoiceCallStatuses::isMissedStatus('ANSWERED'));
        $this->assertFalse(BonvoiceCallStatuses::isMissedStatus('COMPLETED'));
    }

    public function test_lifecycle_rank_is_monotonic(): void
    {
        $this->assertSame(0, BonvoiceCallStatuses::lifecycleRank('0'));
        $this->assertSame(1, BonvoiceCallStatuses::lifecycleRank('0.5'));
        $this->assertSame(2, BonvoiceCallStatuses::lifecycleRank('1'));
        $this->assertSame(3, BonvoiceCallStatuses::lifecycleRank('2'));
        $this->assertNull(BonvoiceCallStatuses::lifecycleRank('Support'));
    }

    public function test_transition_to_answered_from_ringing_call_type(): void
    {
        $this->assertTrue(BonvoiceCallStatuses::transitionedToAnswered(
            null,
            null,
            '0.5',
            '1',
        ));

        $this->assertFalse(BonvoiceCallStatuses::transitionedToAnswered(
            null,
            null,
            '1',
            '1',
        ));
    }
}
