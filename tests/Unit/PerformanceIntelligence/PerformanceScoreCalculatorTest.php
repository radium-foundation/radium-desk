<?php

namespace Tests\Unit\PerformanceIntelligence;

use App\Data\PerformanceIntelligence\PerformanceDayInputs;
use App\Services\PerformanceIntelligence\PerformanceScoreCalculator;
use Tests\TestCase;

class PerformanceScoreCalculatorTest extends TestCase
{
    public function test_resolves_outscore_status_spam_on_composite(): void
    {
        $calculator = app(PerformanceScoreCalculator::class);

        $resolver = $calculator->calculate(new PerformanceDayInputs(
            userId: 1,
            workDate: '2026-08-04',
            casesWorked: 2,
            customerTouches: 4,
            touchBreakdown: [
                'calls' => 0,
                'whatsapp' => 0,
                'emails' => 0,
                'remarks' => 0,
                'status_updates' => 2,
            ],
            resolvedCount: 2,
            closedCount: 2,
            reopenCount: 0,
            refundDecisionCount: 0,
            assignOrEscalateCount: 0,
            answeredCallCount: 0,
            attendanceExtra: false,
            attendanceOnLeave: false,
            isCompanyHoliday: false,
            isWorkingDay: true,
            overtimeSeconds: 0,
            attendanceStatus: 'completed',
        ));

        $spammer = $calculator->calculate(new PerformanceDayInputs(
            userId: 2,
            workDate: '2026-08-04',
            casesWorked: 1,
            customerTouches: 40,
            touchBreakdown: [
                'calls' => 0,
                'whatsapp' => 0,
                'emails' => 0,
                'remarks' => 20,
                'status_updates' => 20,
            ],
            resolvedCount: 0,
            closedCount: 0,
            reopenCount: 0,
            refundDecisionCount: 0,
            assignOrEscalateCount: 0,
            answeredCallCount: 0,
            attendanceExtra: false,
            attendanceOnLeave: false,
            isCompanyHoliday: false,
            isWorkingDay: true,
            overtimeSeconds: 0,
            attendanceStatus: 'active',
        ));

        $this->assertGreaterThan($spammer->compositeScore, $resolver->compositeScore);
        $this->assertNotEmpty($resolver->explanations['outcome']);
        $this->assertNotEmpty($resolver->explanations['composite']);
    }

    public function test_reach_zero_without_substance(): void
    {
        $calculator = app(PerformanceScoreCalculator::class);

        $result = $calculator->calculate(new PerformanceDayInputs(
            userId: 1,
            workDate: '2026-08-04',
            casesWorked: 5,
            customerTouches: 5,
            touchBreakdown: [
                'calls' => 0,
                'whatsapp' => 0,
                'emails' => 0,
                'remarks' => 5,
                'status_updates' => 0,
            ],
            resolvedCount: 0,
            closedCount: 0,
            reopenCount: 0,
            refundDecisionCount: 0,
            assignOrEscalateCount: 5,
            answeredCallCount: 0,
            attendanceExtra: false,
            attendanceOnLeave: false,
            isCompanyHoliday: false,
            isWorkingDay: true,
            overtimeSeconds: 0,
            attendanceStatus: 'active',
        ));

        // Assign-only + remarks still give contribution, but Reach requires outcome/interaction substance.
        // Remarks alone are hygiene — substance check uses WA/email/calls/outcome.
        $this->assertSame(0, $result->reachScore);
    }

    public function test_leave_without_outcomes_gets_zero_commitment(): void
    {
        $calculator = app(PerformanceScoreCalculator::class);

        $result = $calculator->calculate(new PerformanceDayInputs(
            userId: 1,
            workDate: '2026-08-04',
            casesWorked: 0,
            customerTouches: 0,
            touchBreakdown: [
                'calls' => 0,
                'whatsapp' => 0,
                'emails' => 0,
                'remarks' => 0,
                'status_updates' => 0,
            ],
            resolvedCount: 0,
            closedCount: 0,
            reopenCount: 0,
            refundDecisionCount: 0,
            assignOrEscalateCount: 0,
            answeredCallCount: 0,
            attendanceExtra: false,
            attendanceOnLeave: true,
            isCompanyHoliday: false,
            isWorkingDay: true,
            overtimeSeconds: 3600,
            attendanceStatus: 'on_leave',
        ));

        $this->assertSame(0, $result->commitmentScore);
    }
}
