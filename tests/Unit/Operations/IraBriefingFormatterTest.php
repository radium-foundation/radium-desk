<?php

namespace Tests\Unit\Operations;

use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraRiskCategory;
use App\Services\Operations\IraBriefingFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IraBriefingFormatterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_briefing_separates_critical_vs_monitoring(): void
    {
        $briefing = $this->makeBriefing(
            operations: [
                'action_required' => 3,
                'attention' => 0,
                'scheduled' => 0,
                'waiting' => 14,
                'overdue' => 3,
                'warning' => 14,
                'open_cases' => 33,
            ],
            risks: [
                new IraOperationalRisk(
                    key: 'customer.sla_danger',
                    title: 'SLA Breach Risk',
                    category: IraRiskCategory::Customer,
                    severity: AIRiskLevel::High,
                    message: 'Cases risk SLA breach.',
                    context: ['overdue' => 3, 'warning' => 14],
                ),
            ],
        );

        $formatted = app(IraBriefingFormatter::class)->format($briefing);

        $this->assertSame(3, $formatted->criticalRiskCount);
        $this->assertSame(14, $formatted->monitoringRiskCount);
        $this->assertStringContainsString('3 cases require action', implode("\n", $formatted->attentionLines));
        $this->assertStringContainsString('14 being monitored', implode("\n", $formatted->attentionLines));
        $this->assertStringNotContainsString('SLA risks', $formatted->telegramMessage);
    }

    public function test_empty_presence_does_not_show_misleading_zero_active(): void
    {
        $briefing = $this->makeBriefing(
            team: [
                'available' => 0,
                'leave' => 0,
                'total_members' => 1,
                'average_active_seconds' => 0,
            ],
        );

        $formatted = app(IraBriefingFormatter::class)->format($briefing);

        $this->assertTrue($formatted->teamPresenceCollecting);
        $this->assertSame(['Presence data collecting'], $formatted->teamLines);
        $this->assertStringContainsString('Presence data collecting', $formatted->telegramMessage);
        $this->assertStringNotContainsString('0 active', $formatted->telegramMessage);
        $this->assertStringNotContainsString('Working Now: 0', $formatted->telegramMessage);
    }

    public function test_correct_greeting_by_time(): void
    {
        $formatter = app(IraBriefingFormatter::class);
        config(['app.timezone' => 'Asia/Kolkata']);

        Carbon::setTestNow(Carbon::parse('2026-07-05 08:00:00', 'Asia/Kolkata'));
        $this->assertSame('Good morning Ravi.', $formatter->greeting('Ravi'));

        Carbon::setTestNow(Carbon::parse('2026-07-05 14:00:00', 'Asia/Kolkata'));
        $this->assertSame('Good afternoon Ravi.', $formatter->greeting('Ravi'));

        Carbon::setTestNow(Carbon::parse('2026-07-05 19:00:00', 'Asia/Kolkata'));
        $this->assertSame('Good evening Ravi.', $formatter->greeting('Ravi'));
    }

    public function test_recommendation_included_with_customer_priority(): void
    {
        $briefing = $this->makeBriefing(
            recommendations: [
                new IraOperationalRecommendation(
                    key: 'capacity.assign.1',
                    message: 'Agent has capacity.',
                ),
                new IraOperationalRecommendation(
                    key: 'waiting.send_reminders',
                    message: 'Waiting customers older than 7 days should receive a reminder (4 case(s)).',
                ),
            ],
        );

        $formatted = app(IraBriefingFormatter::class)->format($briefing);

        $this->assertSame('Follow up on long-waiting customers.', $formatted->suggestion);
        $this->assertStringContainsString('💡 Ira Suggestion', $formatted->telegramMessage);
        $this->assertStringContainsString('Follow up on long-waiting customers.', $formatted->telegramMessage);
    }

    public function test_telegram_message_stays_concise(): void
    {
        $briefing = $this->makeBriefing(
            operations: [
                'action_required' => 33,
                'attention' => 5,
                'scheduled' => 0,
                'waiting' => 132,
                'overdue' => 3,
                'warning' => 14,
                'open_cases' => 33,
            ],
            team: [
                'available' => 4,
                'leave' => 0,
                'total_members' => 4,
                'average_active_seconds' => 3600,
            ],
            recommendations: [
                new IraOperationalRecommendation(
                    key: 'trend.product.FM220',
                    message: 'FM220 support requests increased 40% this week.',
                    context: ['prefix' => 'FM220'],
                ),
            ],
        );

        $formatted = app(IraBriefingFormatter::class)->format($briefing, 'Ravi');

        $this->assertLessThanOrEqual(900, strlen($formatted->telegramMessage));
        $this->assertStringContainsString('📊 Operations', $formatted->telegramMessage);
        $this->assertStringContainsString('👥 Team', $formatted->telegramMessage);
        $this->assertStringContainsString('⚠️ Attention', $formatted->telegramMessage);
        $this->assertStringContainsString('Review FM220 pending cases first.', $formatted->telegramMessage);
        $this->assertLessThanOrEqual(3, count($formatted->attentionLines));
    }

    /**
     * @param  array<string, int|float|null>  $operations
     * @param  array<string, int|float|null>  $team
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    private function makeBriefing(
        array $operations = [],
        array $team = [],
        array $risks = [],
        array $recommendations = [],
    ): IraMorningBriefing {
        return new IraMorningBriefing(
            greeting: 'Good morning.',
            summary: 'Operations look healthy today.',
            healthStatus: 'healthy',
            highlights: [],
            risks: $risks,
            recommendations: $recommendations,
            snapshot: new IraOperationalSnapshotData(
                date: '2026-07-05',
                operations: array_merge([
                    'open_cases' => 0,
                    'scheduled' => 0,
                    'waiting' => 0,
                    'overdue' => 0,
                    'warning' => 0,
                    'action_required' => 0,
                    'attention' => 0,
                ], $operations),
                team: array_merge([
                    'available' => 0,
                    'leave' => 0,
                    'total_members' => 0,
                    'average_active_seconds' => 0,
                ], $team),
                performance: ['completed_cases' => 0],
            ),
        );
    }
}
