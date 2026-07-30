<?php

namespace Tests\Unit\Workforce\Contribution;

use App\Enums\ContributionSignalId;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Workforce\Contribution\ContributionActivityQuery;
use App\Services\Workforce\Contribution\Signals\CaseSignalCollector;
use App\Services\Workforce\Contribution\Signals\EmailSignalCollector;
use App\Services\Workforce\Contribution\Signals\OrderSignalCollector;
use App\Services\Workforce\Contribution\Signals\WhatsAppSignalCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ContributionSignalCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_case_signal_collector_sums_session_metric_only(): void
    {
        $collector = new CaseSignalCollector;
        $sessions = new Collection([
            (object) ['cases_handled_count' => 2],
            (object) ['cases_handled_count' => 3],
        ]);

        $signal = $collector->collect(new User, Carbon::parse('2026-07-07'), $sessions);

        $this->assertSame(ContributionSignalId::CasesHandled, $signal->id);
        $this->assertSame(5, $signal->value);
        $this->assertTrue($signal->available);
    }

    public function test_email_and_whatsapp_collectors_are_day_bounded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $day = Carbon::parse('2026-07-07', 'Asia/Kolkata');

        $this->writeAuditLog([
            'user_id' => $user->id,
            'event' => 'notification.dispatched',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'),
        ]);
        $this->writeAuditLog([
            'user_id' => $user->id,
            'event' => 'notification.dispatched',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => Carbon::parse('2026-07-08 10:00:00', 'Asia/Kolkata'),
        ]);
        $this->writeAuditLog([
            'user_id' => $user->id,
            'event' => 'whatsapp.template_sent',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => ['trigger_source' => WhatsAppTemplateTriggerSource::Manual->value],
            'created_at' => Carbon::parse('2026-07-07 11:00:00', 'Asia/Kolkata'),
        ]);

        $query = app(ContributionActivityQuery::class);
        $email = (new EmailSignalCollector($query))->collect($user, $day, collect());
        $whatsapp = (new WhatsAppSignalCollector($query))->collect($user, $day, collect());

        $this->assertSame(1, $email->value);
        $this->assertSame(1, $whatsapp->value);
    }

    public function test_order_signal_collector_uses_activation_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        $day = Carbon::parse('2026-07-07', 'Asia/Kolkata');

        $this->writeAuditLog([
            'user_id' => $user->id,
            'event' => 'service_reference.assigned',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => Carbon::parse('2026-07-07 09:30:00', 'Asia/Kolkata'),
        ]);

        $signal = (new OrderSignalCollector(app(ContributionActivityQuery::class)))
            ->collect($user, $day, collect());

        $this->assertSame(ContributionSignalId::OrdersActivated, $signal->id);
        $this->assertSame(1, $signal->value);
        $this->assertTrue($signal->available);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeAuditLog(array $attributes): void
    {
        $createdAt = $attributes['created_at'] ?? now();
        unset($attributes['created_at']);

        $log = AuditLog::query()->create($attributes);
        $log->forceFill(['created_at' => $createdAt])->saveQuietly();
    }
}
