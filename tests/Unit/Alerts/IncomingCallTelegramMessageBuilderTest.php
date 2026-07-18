<?php

namespace Tests\Unit\Alerts;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Alerts\IncomingCallTelegramMessageBuilder;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncomingCallTelegramMessageBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:30:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_builds_masked_personal_telegram_message_for_known_customer(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD3444319',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Known Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Telegram message test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);
        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-tg-1',
            'leg' => 'A',
            'customer_phone' => '9876543210',
            'source_number' => '9876543210',
            'destination_number' => '1800123456',
            'direction' => 'Inbound',
            'status' => 'Ringing',
            'account_id' => 'acct-001',
            'event_id' => 'evt-tg-1',
            'payload' => [],
        ]);
        $alert = BonvoiceCallAlert::query()->create([
            'bonvoice_call_event_id' => $event->id,
            'call_id' => 'call-tg-1',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::CustomerFound,
            'customer_phone' => '9876543210',
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'notified_at' => now(),
        ]);

        $message = app(IncomingCallTelegramMessageBuilder::class)->build(
            $alert,
            route('incidents.show', $incident),
        );

        $this->assertStringContainsString('📞 Incoming Call', $message);
        $this->assertStringContainsString('Customer: Known Customer', $message);
        $this->assertStringContainsString('Mobile: ******3210', $message);
        $this->assertStringContainsString('Reference: '.$incident->reference_no, $message);
        $this->assertStringContainsString('Open in Radium Desk', $message);
        $this->assertStringContainsString(route('incidents.show', $incident), $message);
        $this->assertStringNotContainsString('9876543210', $message);
    }

    public function test_mask_mobile_handles_short_and_empty_values(): void
    {
        $builder = app(IncomingCallTelegramMessageBuilder::class);

        $this->assertSame('Unknown', $builder->maskMobile(null));
        $this->assertSame('****', $builder->maskMobile('1234'));
        $this->assertSame('********7890', $builder->maskMobile('+91 98765 47890'));
    }
}
