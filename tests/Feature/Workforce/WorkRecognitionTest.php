<?php

namespace Tests\Feature\Workforce;

use App\Contracts\Workforce\IncentivePolicy;
use App\Enums\AttendanceDayStatus;
use App\Enums\RecognitionRecommendation;
use App\Enums\RecognitionReviewStatus;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Enums\WorkSessionEndReason;
use App\Jobs\ScanWorkRecognitionMonthJob;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkRecognitionReview;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Services\Workforce\Recognition\WorkRecognitionReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkRecognitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        config([
            'workforce_recognition.enabled' => true,
            'workforce.attendance_management.restricted' => false,
            'workforce_calendar.retroactive_leave_days' => 60,
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scan_creates_pending_review_for_extra_day_with_activity(): void
    {
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $review = app(WorkRecognitionReviewService::class)->ensurePendingFor(
            $agent,
            Carbon::parse('2026-07-12'),
        );

        $this->assertNotNull($review);
        $this->assertTrue($review->isPending());
        $this->assertSame(RecognitionReviewStatus::PendingReview, $review->status);
        $this->assertNotNull($review->ira_recommendation);
        $this->assertNotEmpty($review->ira_rationale);
        $this->assertDatabaseHas('audit_logs', [
            'event' => WorkforceAuditEvent::RecognitionCreated->value,
            'auditable_id' => $review->id,
        ]);
    }

    public function test_leave_day_is_not_a_recognition_candidate(): void
    {
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-12',
            'status' => AttendanceDayStatus::OnLeave,
            'calendar_status' => 'weekly_off',
            'is_working_day' => false,
            'is_company_holiday' => false,
            'is_on_leave' => true,
            'has_schedule' => true,
            'session_count' => 0,
            'session_duration_seconds' => 0,
            'active_duration_seconds' => 0,
            'idle_duration_seconds' => 0,
            'lunch_duration_seconds' => 0,
            'break_duration_seconds' => 0,
            'extra_idle_duration_seconds' => 0,
            'overtime_seconds' => 0,
            'away_timeout_count' => 0,
            'manual_logout_count' => 0,
            'computed_at' => now(),
            'source_version' => 1,
        ]);

        $review = app(WorkRecognitionReviewService::class)->ensurePendingFor(
            $agent,
            Carbon::parse('2026-07-12'),
        );

        $this->assertNull($review);
    }

    public function test_manager_can_decide_and_override_requires_reason(): void
    {
        $ops = $this->opsAdmin();
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $review = app(WorkRecognitionReviewService::class)->ensurePendingFor(
            $agent,
            Carbon::parse('2026-07-12'),
        );
        $this->assertNotNull($review);

        try {
            app(WorkRecognitionReviewService::class)->decide(
                $review,
                $ops,
                RecognitionRecommendation::Bonus,
                null,
            );
            $this->fail('Override without reason should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decision_reason', $e->errors());
        }

        $decided = app(WorkRecognitionReviewService::class)->decide(
            $review->fresh(),
            $ops,
            RecognitionRecommendation::FullExtra,
            $review->ira_recommendation === RecognitionRecommendation::FullExtra
                ? null
                : 'Verified cases closed on weekly off',
        );

        $this->assertSame(RecognitionReviewStatus::Decided, $decided->status);
        $this->assertSame(RecognitionRecommendation::FullExtra, $decided->decision);
        $this->assertDatabaseHas('audit_logs', [
            'event' => WorkforceAuditEvent::RecognitionDecided->value,
            'user_id' => $ops->id,
        ]);
    }

    public function test_incentive_policy_returns_approved_awards_only(): void
    {
        $ops = $this->opsAdmin();
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');
        $this->seedExtraDay($agent, '2026-07-19');

        $service = app(WorkRecognitionReviewService::class);
        $a = $service->ensurePendingFor($agent, Carbon::parse('2026-07-12'));
        $b = $service->ensurePendingFor($agent, Carbon::parse('2026-07-19'));

        $service->decide($a, $ops, RecognitionRecommendation::Appreciation, 'ok');
        $service->decide($b, $ops, RecognitionRecommendation::NoBenefit, 'ok');

        $awards = app(IncentivePolicy::class)->approvedAwardsForMonth(Carbon::parse('2026-07-01'));

        $this->assertCount(1, $awards);
        $this->assertSame(RecognitionRecommendation::Appreciation, $awards->first()->decision);
    }

    public function test_recognition_index_requires_permission_and_flag(): void
    {
        $ops = $this->opsAdmin();

        config(['workforce_recognition.enabled' => false]);
        $this->actingAs($ops)
            ->get(route('workforce-management.recognition.index'))
            ->assertNotFound();

        config(['workforce_recognition.enabled' => true]);
        $this->actingAs($ops)
            ->get(route('workforce-management.recognition.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Work Recognition');
    }

    public function test_admin_cannot_decide_without_review_permission(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin-only@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');
        $review = app(WorkRecognitionReviewService::class)->ensurePendingFor(
            $agent,
            Carbon::parse('2026-07-12'),
        );

        $this->actingAs($admin)
            ->post(route('workforce-management.recognition.decide', $review), [
                'decision' => RecognitionRecommendation::Appreciation->value,
            ])
            ->assertForbidden();
    }

    public function test_recognition_events_are_not_reserved(): void
    {
        $this->assertFalse(WorkforceEventType::WeeklyOffWorked->isReserved());
        $this->assertFalse(WorkforceEventType::HolidayWorked->isReserved());
        $this->assertFalse(WorkforceEventType::RecognitionRecommended->isReserved());
        $this->assertFalse(WorkforceEventType::RecognitionDecided->isReserved());
        $this->assertTrue(WorkforceEventType::IncentiveAwarded->isReserved());
    }

    public function test_disabled_flag_skips_create(): void
    {
        config(['workforce_recognition.enabled' => false]);
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $this->assertNull(app(WorkRecognitionReviewService::class)->ensurePendingFor(
            $agent,
            Carbon::parse('2026-07-12'),
        ));
        $this->assertSame(0, WorkRecognitionReview::query()->count());
    }

    public function test_second_decide_does_not_emit_another_recognition_decided_event(): void
    {
        $ops = $this->opsAdmin();
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $recording = $this->bindRecordingPublisher();
        $service = app(WorkRecognitionReviewService::class);
        $review = $service->ensurePendingFor($agent, Carbon::parse('2026-07-12'));
        $this->assertNotNull($review);

        $recording->events = [];

        $first = $service->decide(
            $review,
            $ops,
            RecognitionRecommendation::Appreciation,
            $review->ira_recommendation === RecognitionRecommendation::Appreciation ? null : 'ok',
        );
        $second = $service->decide(
            $first,
            $ops,
            RecognitionRecommendation::Bonus,
            'should be ignored — already decided',
        );

        $this->assertSame(RecognitionRecommendation::Appreciation, $second->decision);
        $decidedEvents = array_values(array_filter(
            $recording->events,
            static fn ($event): bool => $event->type === WorkforceEventType::RecognitionDecided,
        ));
        $this->assertCount(1, $decidedEvents);
    }

    public function test_refresh_with_identical_recommendation_does_not_reemit_recognition_recommended(): void
    {
        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $recording = $this->bindRecordingPublisher();
        $service = app(WorkRecognitionReviewService::class);
        $review = $service->ensurePendingFor($agent, Carbon::parse('2026-07-12'));
        $this->assertNotNull($review);

        $recommendedBefore = count(array_filter(
            $recording->events,
            static fn ($event): bool => $event->type === WorkforceEventType::RecognitionRecommended,
        ));
        $this->assertSame(1, $recommendedBefore);

        $service->refreshPending($review->fresh());

        $recommendedAfter = count(array_filter(
            $recording->events,
            static fn ($event): bool => $event->type === WorkforceEventType::RecognitionRecommended,
        ));
        $this->assertSame(1, $recommendedAfter);
    }

    public function test_evidence_snapshot_does_not_publish_contribution_qualified(): void
    {
        config([
            'workforce_contribution.enabled' => true,
            'workforce_contribution.packs.support_agent.strategy' => 'any_of',
            'workforce_contribution.packs.support_agent.signals.active_duration.enabled' => true,
            'workforce_contribution.packs.support_agent.signals.active_duration.normal' => 1,
            'workforce_contribution.packs.support_agent.signals.active_duration.high' => 1,
        ]);

        $agent = $this->createScheduledAgent(effectiveFrom: '2026-07-01');
        $this->seedExtraDay($agent, '2026-07-12');

        $recording = $this->bindRecordingPublisher();

        app(\App\Services\Workforce\Recognition\EvidenceSnapshotBuilder::class)->build(
            $agent,
            Carbon::parse('2026-07-12'),
        );

        $qualified = array_values(array_filter(
            $recording->events,
            static fn ($event): bool => $event->type === WorkforceEventType::ContributionQualified,
        ));
        $this->assertSame([], $qualified);
    }

    public function test_http_scan_dispatches_queued_job(): void
    {
        Queue::fake();

        $ops = $this->opsAdmin();

        $this->actingAs($ops)
            ->post(route('workforce-management.recognition.scan'), [
                'month' => '2026-07',
            ])
            ->assertRedirect(route('workforce-management.recognition.index', ['month' => '2026-07']))
            ->assertSessionHas('status', 'work-recognition-scan-queued');

        Queue::assertPushed(ScanWorkRecognitionMonthJob::class, function (ScanWorkRecognitionMonthJob $job): bool {
            return $job->month === '2026-07';
        });
    }

    private function bindRecordingPublisher(): object
    {
        $recording = new class implements \App\Contracts\Workforce\WorkforceEventPublisher
        {
            /** @var list<\App\Data\Workforce\WorkforceEvent> */
            public array $events = [];

            public function publish(\App\Data\Workforce\WorkforceEvent $event): void
            {
                $this->events[] = $event;
            }
        };

        $this->app->instance('workforce.events.inner_publisher', $recording);
        $this->app->instance(
            \App\Contracts\Workforce\WorkforceEventPublisher::class,
            new \App\Services\Workforce\Events\SafeWorkforceEventPublisher($recording),
        );

        $this->app->forgetInstance(\App\Services\Workforce\Contribution\ContributionEngine::class);
        $this->app->forgetInstance(\App\Services\Workforce\Recognition\EvidenceSnapshotBuilder::class);
        $this->app->forgetInstance(\App\Services\Workforce\Recognition\WorkRecognitionReviewService::class);

        return $recording;
    }

    private function opsAdmin(): User
    {
        $user = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    private function createScheduledAgent(string $effectiveFrom): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [0],
            'effective_from' => $effectiveFrom,
        ]);

        return $agent->fresh(['roles']);
    }

    private function seedExtraDay(User $user, string $date): void
    {
        WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'login_at' => Carbon::parse($date.' 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse($date.' 14:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 4 * 3600,
            'active_duration_seconds' => 3 * 3600,
            'on_time_login' => true,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'status' => AttendanceDayStatus::Extra,
            'calendar_status' => 'weekly_off',
            'is_working_day' => false,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'session_duration_seconds' => 4 * 3600,
            'active_duration_seconds' => 3 * 3600,
            'idle_duration_seconds' => 0,
            'lunch_duration_seconds' => 0,
            'break_duration_seconds' => 0,
            'extra_idle_duration_seconds' => 0,
            'overtime_seconds' => 0,
            'away_timeout_count' => 0,
            'manual_logout_count' => 1,
            'computed_at' => now(),
            'source_version' => 1,
        ]);
    }
}
