<?php

namespace Tests\Feature\Customer360;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\Remark;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Sc35130TimelineRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.business_timeline.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sc35130_business_timeline_produces_canonical_milestones(): void
    {
        [$agent, $incident] = $this->createSc35130Fixture();

        $viewModel = app(Customer360TimelineService::class)->businessForIncident($incident, offset: 0, limit: 50);
        $titles = $viewModel->items()->pluck('title')->all();

        $this->assertMilestoneNewestFirstOrder($titles, [
            'internal note' => fn (string $title): bool => $title === 'Internal note added.',
            'whatsapp' => fn (string $title): bool => str_contains($title, 'Avinash') && str_contains($title, 'WhatsApp'),
            'case closed' => fn (string $title): bool => $title === 'Case closed.',
            'customer created' => fn (string $title): bool => $title === 'Customer created service request.',
            'system updates' => fn (string $title): bool => str_contains(strtolower($title), 'system update'),
            'serial verified' => fn (string $title): bool => $title === 'Serial number verified.',
            'assigned to vanshika' => fn (string $title): bool => str_contains($title, 'Vanshika'),
            'assigned to avinash' => fn (string $title): bool => $title === 'Assigned to Avinash.',
            'appointment' => fn (string $title): bool => str_contains(strtolower($title), 'appointment'),
            'waiting cleared' => fn (string $title): bool => $title === 'Waiting cleared.',
            'inbound call' => fn (string $title): bool => str_contains($title, 'Inbound Call'),
            'payment received' => fn (string $title): bool => $title === 'Payment received.',
        ]);

        $this->assertSame(1, collect($titles)->filter(fn (string $title): bool => $title === 'Payment received.')->count());
        $this->assertSame(1, collect($titles)->filter(fn (string $title): bool => $title === 'Customer created service request.')->count());
        $this->assertSame(1, collect($titles)->filter(fn (string $title): bool => $title === 'Serial number verified.')->count());
        $this->assertTrue(collect($titles)->contains(fn (string $title): bool => str_contains($title, 'Avinash')));
        $this->assertTrue(collect($titles)->contains(fn (string $title): bool => str_contains($title, 'Vanshika')));
        $this->assertFalse(collect($titles)->contains(fn (string $title): bool => str_contains($title, 'Device Model Assigned')));
        $this->assertSame(
            1,
            collect($titles)->filter(fn (string $title): bool => str_contains(strtolower($title), 'appointment'))->count(),
        );

        $payment = $viewModel->items()->first(fn ($item) => $item->title === 'Payment received.');
        $this->assertNotNull($payment);
        $this->assertCount(2, $payment->rawEvents);
        $this->assertTrue(collect($payment->rawEvents)->contains(fn ($event) => $event->dedupeKey === 'payment:order:'.$incident->order_id));
        $this->assertTrue(collect($payment->rawEvents)->contains(fn ($event) => str_starts_with($event->dedupeKey, 'payment:audit:')));

        $serial = $viewModel->items()->first(fn ($item) => $item->title === 'Serial number verified.');
        $this->assertNotNull($serial);
        $this->assertGreaterThanOrEqual(2, count($serial->rawEvents));
        $this->assertTrue(collect($serial->rawEvents)->contains(fn ($event) => str_starts_with($event->dedupeKey, 'audit:')));
        $this->assertTrue(collect($serial->rawEvents)->contains(fn ($event) => str_starts_with($event->dedupeKey, 'serial-assigned:')));

        $html = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0&limit=50')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Show Raw Events', $html);
        $this->assertStringContainsString('data-timeline-raw-event', $html);
        $this->assertStringContainsString('Vanshika', $html);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createSc35130Fixture(): array
    {
        $system = User::factory()->create(['name' => 'Ira']);
        $system->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $avinash = User::factory()->create(['name' => 'Avinash Jha']);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $vanshika = User::factory()->create(['name' => 'Vanshika Baniwal']);
        $vanshika->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $abhinav = User::factory()->create(['name' => 'Abhinav']);
        $abhinav->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $agent = $avinash;

        $order = Order::query()->create([
            'order_id' => 'RD3484558',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'customer_phone' => '9876500000',
            'payment_date' => Carbon::parse('2026-08-11 09:46:44', 'Asia/Kolkata'),
            'created_by' => $system->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC35130',
            'category' => 'General',
            'source' => IncidentSource::Cashfree->value,
            'title' => 'SC35130 fixture',
            'description' => 'SC35130 fixture.',
            'status' => IncidentStatus::Closed->value,
            'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
            'assigned_to_user_id' => $vanshika->id,
            'created_by' => $system->id,
            'updated_by' => $avinash->id,
            'created_at' => Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-08-11 10:33:34', 'Asia/Kolkata'),
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => [],
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => 'device-model.bulk-assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'created_at' => Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => ['device_model' => 'MFS 110'],
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => 'service_case.assigned',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:08:51', 'Asia/Kolkata'),
            'old_values' => ['assigned_to_user_id' => null],
            'new_values' => [
                'assigned_to_user_id' => $avinash->id,
                'assignment_origin' => 'auto',
            ],
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:15:44', 'Asia/Kolkata'),
            'old_values' => ['assigned_to_user_id' => $avinash->id],
            'new_values' => [
                'assigned_to_user_id' => $vanshika->id,
                'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
                'assignment_method' => 'smart',
            ],
        ]);

        $serialAudit = AuditLog::query()->create([
            'user_id' => $abhinav->id,
            'event' => 'serial.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'created_at' => Carbon::parse('2026-08-11 10:12:53', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => ['serial_number' => '8628029'],
        ]);

        AuditLog::query()->create([
            'user_id' => $abhinav->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:12:53', 'Asia/Kolkata'),
            'old_values' => ['status' => IncidentStatus::AwaitingProductDetails->value],
            'new_values' => ['status' => IncidentStatus::Open->value],
        ]);

        AuditLog::query()->create([
            'user_id' => $avinash->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:33:34', 'Asia/Kolkata'),
            'old_values' => ['status' => IncidentStatus::Open->value],
            'new_values' => ['status' => IncidentStatus::Closed->value],
        ]);

        AuditLog::query()->create([
            'user_id' => $avinash->id,
            'event' => 'transaction.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'created_at' => Carbon::parse('2026-08-11 10:33:34', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => ['transaction_id' => 'TXN-123'],
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => Carbon::parse('2026-08-12', 'Asia/Kolkata'),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876500000',
            'normalized_phone' => '9876500000',
            'status' => SupportAppointmentStatus::Completed,
            'created_at' => Carbon::parse('2026-08-11 10:06:26', 'Asia/Kolkata'),
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:06:51', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => [
                'notification_type' => 'Support appointment booked',
                'aggregate_success' => true,
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true],
                    ['channel' => 'email', 'success' => true],
                ],
            ],
        ]);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => 'serial_number',
            'started_at' => Carbon::parse('2026-08-11 10:05:31', 'Asia/Kolkata'),
            'cleared_at' => Carbon::parse('2026-08-11 10:06:28', 'Asia/Kolkata'),
        ]);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-sc35130',
            'leg' => 'call',
            'customer_phone' => '9876500000',
            'source_number' => '9876500000',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'started_at' => Carbon::parse('2026-08-11 10:04:33', 'Asia/Kolkata'),
            'payload' => ['CallDuration' => '30'],
        ]);

        Remark::query()->create([
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'user_id' => $avinash->id,
            'body' => 'Sent driver installation instructions to the customer.',
            'created_at' => Carbon::parse('2026-08-11 10:34:12', 'Asia/Kolkata'),
        ]);

        AuditLog::query()->create([
            'user_id' => $avinash->id,
            'event' => NotificationAuditTrailService::EVENT_DISPATCHED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:34:14', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => [
                'notification_type' => 'Driver Installation Guide Sent',
                'aggregate_success' => true,
                'channel_results' => [
                    ['channel' => 'whatsapp', 'success' => true],
                    ['channel' => 'email', 'success' => true],
                ],
            ],
        ]);

        // Ensure serial-assigned mapper key aligns with audit id for collapse pairing.
        $serialAudit->update(['id' => 1100666]);

        return [$agent, $incident->fresh(['order'])];
    }

    /**
     * @param  list<string>  $titles  Newest-first milestone titles
     * @param  array<string, callable(string): bool>  $sequence
     */
    private function assertMilestoneNewestFirstOrder(array $titles, array $sequence): void
    {
        $indices = [];

        foreach ($sequence as $label => $matcher) {
            $index = collect($titles)->search($matcher);
            $this->assertNotFalse($index, "Missing milestone in timeline: {$label}");
            $indices[$label] = $index;
        }

        $labels = array_keys($sequence);

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $newer = $labels[$i];
            $older = $labels[$i + 1];

            $this->assertLessThan(
                $indices[$older],
                $indices[$newer],
                "Expected {$newer} (index {$indices[$newer]}) to appear before {$older} (index {$indices[$older]}) in newest-first timeline.",
            );
        }
    }
}
