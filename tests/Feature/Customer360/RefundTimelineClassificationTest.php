<?php

namespace Tests\Feature\Customer360;

use App\Enums\BusinessMilestoneType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\Sources\OrderCustomerTimelineSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RefundTimelineClassificationTest extends TestCase
{
    use RefreshDatabase;

    private const REFUND_REFERENCE = 'REF-2026-000315';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.business_timeline.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function refundAuditEventProvider(): array
    {
        return [
            'refund.requested' => ['refund.requested', 'Refund request created', ['reference_no' => self::REFUND_REFERENCE]],
            'created' => ['created', 'Refund request created', ['reference_no' => self::REFUND_REFERENCE]],
            'refund.agent_notified' => ['refund.agent_notified', 'Refund agent notified', ['reference_no' => self::REFUND_REFERENCE, 'recipient_id' => 1]],
            'refund.approved' => ['refund.approved', 'Refund request approved', ['reference_no' => self::REFUND_REFERENCE]],
            'refund.execution_started' => ['refund.execution_started', 'Refund execution started', ['reference_no' => self::REFUND_REFERENCE]],
            'refund.completed' => ['refund.completed', 'Refund completed', ['reference_no' => self::REFUND_REFERENCE]],
            'refund.customer_notified' => ['refund.customer_notified', 'Refund customer notified', ['reference_no' => self::REFUND_REFERENCE]],
            'refund.closed' => ['refund.closed', 'Refund closed', ['reference_no' => self::REFUND_REFERENCE]],
        ];
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    #[DataProvider('refundAuditEventProvider')]
    public function test_refund_audit_events_are_not_classified_as_payment(
        string $event,
        string $expectedTitle,
        array $newValues,
    ): void {
        [$order, , $refund, $agent] = $this->createRefundScenario();

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => $event,
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'old_values' => [],
            'new_values' => $newValues,
            'created_at' => Carbon::parse('2026-09-17 18:09:24', 'Asia/Kolkata'),
        ]);

        $timelineEvent = app(OrderCustomerTimelineSource::class, ['order' => $order->fresh()])
            ->collect()
            ->first(fn ($item) => str_starts_with($item->dedupeKey, 'audit:'));

        $this->assertNotNull($timelineEvent);
        $this->assertSame(TimelineEventType::AuditEvent, $timelineEvent->type);
        $this->assertSame($expectedTitle, $timelineEvent->title);
        $this->assertNotSame(TimelineEventType::Payment, $timelineEvent->type);
    }

    public function test_refund_business_timeline_does_not_render_payment_received_milestones(): void
    {
        [$order, $incident, $refund, $agent] = $this->createRefundScenario();

        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.requested',
            createdAt: '2026-09-17 18:09:23',
        );
        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'created',
            createdAt: '2026-09-17 18:09:23',
        );

        foreach (range(1, 4) as $index) {
            $this->seedRefundAudit(
                refund: $refund,
                agent: $agent,
                event: 'refund.agent_notified',
                createdAt: '2026-09-17 18:09:2'.(3 + $index),
                newValues: [
                    'reference_no' => self::REFUND_REFERENCE,
                    'recipient_id' => $index,
                ],
            );
        }

        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.approved',
            createdAt: '2026-09-18 13:47:07',
        );
        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.execution_started',
            createdAt: '2026-09-18 13:47:07',
        );
        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.completed',
            createdAt: '2026-09-18 13:47:42',
        );
        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.closed',
            createdAt: '2026-09-18 13:47:42',
        );
        $this->seedRefundAudit(
            refund: $refund,
            agent: $agent,
            event: 'refund.customer_notified',
            createdAt: '2026-09-18 13:47:42',
        );

        $viewModel = app(Customer360TimelineService::class)->businessForIncident($incident->fresh(['order']), offset: 0, limit: 100);
        $titles = $viewModel->items()->pluck('title')->all();

        $this->assertSame(
            1,
            collect($titles)->filter(fn (string $title): bool => $title === 'Payment received.')->count(),
        );

        $rawTitles = $viewModel->items()
            ->flatMap(fn ($item) => $item->rawEvents)
            ->map(fn ($event) => $event->title)
            ->all();
        $this->assertTrue(
            collect($rawTitles)->contains(fn (string $title): bool => str_contains(strtolower($title), 'refund')),
        );

        $paymentMilestones = $viewModel->items()->filter(fn ($item) => $item->type === BusinessMilestoneType::PaymentReceived);
        $this->assertSame(1, $paymentMilestones->count());
        $this->assertSame('Payment received.', $paymentMilestones->first()?->title);
        $this->assertStringContainsString('499', (string) $paymentMilestones->first()?->summary);
    }

    public function test_real_payment_still_renders_single_payment_received_milestone(): void
    {
        $agent = User::factory()->create(['name' => 'Ira']);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3484558',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'customer_phone' => '9876500000',
            'payment_amount' => 499.00,
            'payment_method' => 'UPI',
            'payment_date' => Carbon::parse('2026-08-11 09:46:44', 'Asia/Kolkata'),
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC35130',
            'category' => 'General',
            'source' => IncidentSource::Cashfree->value,
            'title' => 'Payment regression fixture',
            'description' => 'Payment regression fixture.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 09:47:17', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => [],
        ]);

        $viewModel = app(Customer360TimelineService::class)->businessForIncident($incident->fresh(['order']), offset: 0, limit: 50);

        $this->assertSame(
            1,
            $viewModel->items()->filter(fn ($item) => $item->title === 'Payment received.')->count(),
        );

        $payment = $viewModel->items()->first(fn ($item) => $item->title === 'Payment received.');
        $this->assertNotNull($payment);
        $this->assertCount(2, $payment->rawEvents);
    }

    /**
     * @return array{0: Order, 1: Incident, 2: RefundRequest, 3: User}
     */
    private function createRefundScenario(): array
    {
        $agent = User::factory()->create(['name' => 'Refund Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD2977',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'customer_phone' => '9876500000',
            'payment_amount' => 499.00,
            'payment_method' => 'UPI',
            'payment_date' => Carbon::parse('2026-09-14 19:38:43', 'Asia/Kolkata'),
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC55322',
            'category' => 'General',
            'source' => IncidentSource::Cashfree->value,
            'title' => 'Refund timeline fixture',
            'description' => 'Refund timeline fixture.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-09-14 19:39:04', 'Asia/Kolkata'),
            'old_values' => [],
            'new_values' => [],
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => self::REFUND_REFERENCE,
            'amount' => 499.00,
            'reason' => 'Customer requested refund after service completion.',
            'status' => RefundStatus::Closed,
            'requested_by' => $agent->id,
        ]);

        return [$order, $incident, $refund, $agent];
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function seedRefundAudit(
        RefundRequest $refund,
        User $agent,
        string $event,
        string $createdAt,
        array $newValues = ['reference_no' => self::REFUND_REFERENCE],
    ): void {
        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => $event,
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
            'old_values' => [],
            'new_values' => $newValues,
            'created_at' => Carbon::parse($createdAt, 'Asia/Kolkata'),
        ]);
    }
}
