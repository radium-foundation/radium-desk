<?php

namespace Tests\Feature\CustomerLifecycle;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NewContactIntent;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Inquiry\InquiryOrderLinkService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashfreeToInquiryAutoLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => false]);
    }

    public function test_cashfree_payment_auto_links_open_inquiry_by_phone(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::GeneralSupport->value,
            'customer_name' => 'Caller First',
            'phone' => '9908734801',
            'source' => IncidentSource::Call->value,
            'notes' => 'Called before paying.',
        ])->assertRedirect(route('dashboard'));

        $inquiryIncident = Incident::query()->first();
        $this->assertNotNull($inquiryIncident);
        $this->assertTrue($inquiryIncident->order?->isInquiryOrder() ?? false);
        $referenceNo = $inquiryIncident->reference_no;
        $inquiryOrderId = $inquiryIncident->order_id;

        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, Order::query()->count());

        $linkedIncident = $inquiryIncident->fresh(['order', 'inquiryOriginOrder']);
        $rdOrder = Order::query()->where('cashfree_payment_id', '1453002795')->first();

        $this->assertNotNull($rdOrder);
        $this->assertSame($referenceNo, $linkedIncident->reference_no);
        $this->assertSame($rdOrder->id, $linkedIncident->order_id);
        $this->assertSame($inquiryOrderId, $linkedIncident->inquiry_origin_order_id);
        $this->assertSame('RD3446000', $rdOrder->order_id);
        $this->assertFalse($linkedIncident->order?->isInquiryOrder() ?? true);

        $this->assertDatabaseHas('audit_logs', [
            'event' => InquiryOrderLinkService::AUDIT_EVENT,
            'auditable_id' => $linkedIncident->id,
        ]);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
        $this->assertSame($linkedIncident->id, $log->incident_id);
    }

    public function test_cashfree_payment_without_matching_inquiry_still_creates_new_incident(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(IncidentSource::Cashfree, Incident::query()->first()?->source);
    }

    public function test_linked_cashfree_payment_preserves_inquiry_timeline_in_customer360(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)->post(route('service-requests.quick.store'), [
            'action' => 'new_contact',
            'intent' => NewContactIntent::GeneralSupport->value,
            'customer_name' => 'Caller First',
            'phone' => '9908734801',
            'source' => IncidentSource::Call->value,
            'notes' => 'Called before paying.',
        ]);

        $inquiryIncident = Incident::query()->firstOrFail();

        $inquiryOrder = $inquiryIncident->order;
        $inquiryOrder?->update(['payment_date' => now()->subDay()]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload(
            cfPaymentId: '1453002796',
            orderId: 'RD3446001',
        ))->assertOk();

        $linkedIncident = $inquiryIncident->fresh(['order', 'inquiryOriginOrder']);
        $timeline = app(Customer360TimelineService::class)->forIncident($linkedIncident);
        $dedupeKeys = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->pluck('dedupeKey')
            ->all();

        $this->assertTrue(
            collect($dedupeKeys)->contains(fn (string $key): bool => str_starts_with($key, "inquiry-origin:{$inquiryOrder?->id}:")),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'RD3446000'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }
}
