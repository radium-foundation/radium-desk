<?php

namespace Tests\Feature\Context;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use App\Support\Context\ContextTransparency;
use App\Support\Customer360\Customer360CardCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * BR-03 Phase 1 golden checks: no UI/API regression; metadata only when flagged.
 */
class ContextTransparencyGoldenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_drawer_html_unchanged_in_shape_when_transparency_disabled(): void
    {
        Config::set('context_transparency.enabled', false);

        [$agent, $incident] = $this->createDrawerFixture();

        $response = $this->actingAs($agent)->get(
            route('dashboard.service-cases.customer-360', $incident),
        );

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-customer-360-content', $html);
        $this->assertStringNotContainsString('context-badge', $html);
        $this->assertStringNotContainsString('data-context-scope', $html);
        $this->assertStringNotContainsString('context-case', $html);
    }

    public function test_drawer_html_still_has_no_visible_context_ui_when_transparency_enabled(): void
    {
        Config::set('context_transparency.enabled', true);
        $this->assertTrue(ContextTransparency::enabled());

        [$agent, $incident] = $this->createDrawerFixture();

        $response = $this->actingAs($agent)->get(
            route('dashboard.service-cases.customer-360', $incident),
        );

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-customer-360-content', $html);
        // Phase 1: flag unlocks metadata APIs only — no badge markup in the drawer.
        $this->assertStringNotContainsString('context-badge', $html);
        $this->assertStringNotContainsString('data-context-scope', $html);
    }

    public function test_drawer_data_keys_unchanged_with_flag_on_or_off(): void
    {
        [$agent, $incident] = $this->createDrawerFixture();
        $this->actingAs($agent);

        Config::set('context_transparency.enabled', false);
        $keysOff = array_keys(app(Customer360Service::class)->drawerData($incident));

        Config::set('context_transparency.enabled', true);
        $keysOn = array_keys(app(Customer360Service::class)->drawerData($incident));

        $this->assertSame($keysOff, $keysOn);
        $this->assertNotContains('context_badges', $keysOn);
        $this->assertNotContains('context_transparency', $keysOn);
    }

    public function test_catalog_metadata_available_when_feature_enabled(): void
    {
        Config::set('context_transparency.enabled', true);

        $badge = Customer360CardCatalog::badgeFor(Customer360CardCatalog::REFUND_ACTION);

        $this->assertNotNull($badge);
        $this->assertSame('case', $badge->scope->value);
        $this->assertSame('Refund', $badge->label);

        $export = Customer360CardCatalog::export();
        $this->assertNotEmpty($export);
        $this->assertArrayHasKey('intended_scope', $export[0]);
        $this->assertArrayHasKey('badge', $export[0]);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createDrawerFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BR03-CTX',
            'serial_number' => 'SN-BR03-CTX',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-BR03',
            'customer_name' => 'Context Customer',
            'customer_phone' => '9123400001',
            'customer_email' => 'context@example.com',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'Support',
            'source' => IncidentSource::Call,
            'title' => 'BR-03 context transparency',
            'description' => 'Foundation golden fixture.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
