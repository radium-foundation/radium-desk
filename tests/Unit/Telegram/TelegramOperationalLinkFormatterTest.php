<?php

namespace Tests\Unit\Telegram;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\Telegram\TelegramOperationalLinkFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TelegramOperationalLinkFormatterTest extends TestCase
{
    use RefreshDatabase;

    private TelegramOperationalLinkFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        URL::forceRootUrl('https://desk.radiumbox.com');
        URL::forceScheme('https');
        $this->formatter = app(TelegramOperationalLinkFormatter::class);
    }

    public function test_authorized_operational_links_include_case_refund_and_order_identifiers(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createIncident($admin, 'SC40157');
        $refund = $this->createRefund($admin, 'REF-2026-000212');
        $order = $this->createOrder($admin, 'RD3497836');

        $links = $this->formatter->authorizedOperationalLinks($admin, $incident, $refund, $order);

        $this->assertCount(3, $links);
        $this->assertSame('SC40157', $links[0]['text']);
        $this->assertSame('REF-2026-000212', $links[1]['text']);
        $this->assertSame('RD3497836', $links[2]['text']);
    }

    public function test_outbound_message_adds_text_link_entities_without_visible_urls(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createIncident($admin, 'SC40157');
        $url = $this->formatter->incidentLink($admin, $incident);

        $message = $this->formatter->outboundMessageWithTextLinks('Case: SC40157', [
            ['text' => 'SC40157', 'url' => (string) $url],
        ]);

        $this->assertSame('Case: SC40157', $message->text);
        $this->assertNotNull($message->entities);
        $this->assertStringNotContainsString('https://', $message->text);
        $this->assertSame('text_link', $message->entities[0]['type']);
    }

    public function test_case_link_line_uses_bare_https_url(): void
    {
        $admin = $this->createAdmin();
        $incident = $this->createIncident($admin, 'SC40157');

        $url = $this->formatter->incidentLink($admin, $incident);
        $line = $this->formatter->linkLine('Open Case', $url);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame("Open Case: {$url}", $line);
        $this->assertStringNotContainsString('[', (string) $line);
    }

    public function test_refund_link_line_uses_bare_https_url(): void
    {
        $admin = $this->createAdmin();
        $refund = $this->createRefund($admin, 'REF-2026-000212');

        $url = $this->formatter->refundLink($admin, $refund);
        $line = $this->formatter->linkLine('Open Refund', $url);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame("Open Refund: {$url}", $line);
    }

    public function test_order_link_line_uses_bare_https_url_when_authorized(): void
    {
        $admin = $this->createAdmin();
        $order = $this->createOrder($admin, 'RD3444319');

        $url = $this->formatter->orderLink($admin, $order);
        $line = $this->formatter->linkLine('Open Order', $url);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://', $url);
        $this->assertSame("Open Order: {$url}", $line);
    }

    public function test_order_identifier_fallback_when_route_is_not_authorized(): void
    {
        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $order = $this->createOrder($guest, 'RD-ORDER-FALLBACK');

        $this->assertNull($this->formatter->orderLink($guest, $order));
        $this->assertSame('Order: RD-ORDER-FALLBACK', $this->formatter->orderIdentifierLine($order));
        $this->assertNull($this->formatter->linkLine('Open Order', null));
    }

    public function test_link_line_rejects_unsafe_urls(): void
    {
        $this->assertNull($this->formatter->linkLine('Open Case', 'javascript:alert(1)'));
        $this->assertNull($this->formatter->linkLine('Open Case', '/incidents/1'));
        $this->assertNull($this->formatter->linkLine('Open Case', ''));
    }

    public function test_authorization_expectations_remain_unchanged(): void
    {
        $admin = $this->createAdmin();
        $guest = User::factory()->create(['is_active' => true]);
        $guest->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $incident = $this->createIncident($admin, 'SC40158');
        $refund = $this->createRefund($admin, 'REF-2026-000213');
        $order = $this->createOrder($admin, 'RD3444320');

        $this->assertNotNull($this->formatter->incidentLink($admin, $incident));
        $this->assertNotNull($this->formatter->refundLink($admin, $refund));
        $this->assertNotNull($this->formatter->orderLink($admin, $order));

        $this->assertNull($this->formatter->incidentLink($guest, $incident));
        $this->assertNull($this->formatter->refundLink($guest, $refund));
        $this->assertNull($this->formatter->orderLink($guest, $order));
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createOrder(User $creator, string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createIncident(User $creator, string $referenceNo): Incident
    {
        $order = $this->createOrder($creator, 'RD-'.$referenceNo);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Internal,
            'title' => 'Telegram link test',
            'description' => 'Telegram link test.',
            'status' => \App\Enums\IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createRefund(User $creator, string $referenceNo): RefundRequest
    {
        return RefundRequest::query()->create([
            'order_id' => $this->createOrder($creator, 'RD-'.$referenceNo)->id,
            'reference_no' => $referenceNo,
            'amount' => 499,
            'reason' => 'Telegram link test refund.',
            'status' => \App\Enums\RefundStatus::Pending,
            'requested_by' => $creator->id,
        ]);
    }
}
