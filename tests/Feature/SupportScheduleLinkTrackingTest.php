<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationLinkSource;
use App\Models\Incident;
use App\Models\NotificationLinkClick;
use App\Models\NotificationLinkToken;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationLinkTrackingService;
use App\Services\SupportAppointmentUrlService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportScheduleLinkTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_tracked_schedule_url_records_click_and_redirects_to_signed_booking_page(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TRACK-001',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Tracked schedule case',
            'description' => 'Tracked schedule case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $token = app(NotificationLinkTrackingService::class)->issueToken(
            incident: $incident,
            source: NotificationLinkSource::WhatsApp,
        );

        $response = $this->get(route('support.schedule.track', [
            'token' => $token->token,
            'source' => NotificationLinkSource::WhatsApp->value,
        ]));

        $expectedBookingUrl = app(SupportAppointmentUrlService::class)->bookingUrl($incident);

        $response->assertRedirect($expectedBookingUrl);

        $this->assertDatabaseHas('notification_link_clicks', [
            'notification_link_token_id' => $token->id,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'source' => NotificationLinkSource::WhatsApp->value,
        ]);

        $this->assertSame(1, app(NotificationLinkTrackingService::class)->clickCount(
            NotificationLinkSource::WhatsApp,
            $incident,
        ));
    }

    public function test_expired_tracking_token_returns_not_found(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TRACK-EXP',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Expired token case',
            'description' => 'Expired token case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $token = NotificationLinkToken::query()->create([
            'token' => 'expiredtoken123456789012345678901234567890',
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'source' => NotificationLinkSource::WhatsApp,
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('support.schedule.track', [
            'token' => $token->token,
            'source' => NotificationLinkSource::WhatsApp->value,
        ]))->assertNotFound();

        $this->assertSame(0, NotificationLinkClick::query()->count());
    }

    public function test_mismatched_source_returns_not_found(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TRACK-SRC',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Source mismatch case',
            'description' => 'Source mismatch case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $token = app(NotificationLinkTrackingService::class)->issueToken(
            incident: $incident,
            source: NotificationLinkSource::WhatsApp,
        );

        $this->get(route('support.schedule.track', [
            'token' => $token->token,
            'source' => NotificationLinkSource::Email->value,
        ]))->assertNotFound();
    }
}
