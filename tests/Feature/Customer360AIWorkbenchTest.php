<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIWorkbenchAuditService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360AIWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_360_renders_ai_workbench_panel(): void
    {
        [$agent, $incident] = $this->createFixture();

        $drawerHtml = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-ai-tab', false)
            ->assertDontSee('IRA Workspace', false)
            ->getContent();

        $response = $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.ai-workbench', $incident));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('IRA Workspace', $html);
        $this->assertStringContainsString('Suggested Customer Reply', $html);
        $this->assertStringContainsString('Suggested Internal Note', $html);
        $this->assertStringContainsString('Suggested Checklist', $html);
        $this->assertStringContainsString('Suggested Next Workflow', $html);
        $this->assertStringContainsString('Operator approved', $html);
        $this->assertStringContainsString('data-ai-workbench-copy', $html);
        $this->assertStringContainsString('data-ai-workbench-insert', $html);
        $this->assertStringContainsString('Insert into editor', $html);

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($drawerHtml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $aiTab = $xpath->query('//*[@id="customer-360-tab-ai-assistant"]')->item(0);

        $this->assertNotNull($aiTab, 'IRA AI tab pane should exist.');

        $lazyDocument = new \DOMDocument();
        libxml_use_internal_errors(true);
        $lazyDocument->loadHTML($html);
        libxml_clear_errors();

        $lazyXpath = new \DOMXPath($lazyDocument);
        $workbench = $lazyXpath->query('//*[@id="customer-360-ai-workbench"]')->item(0);

        $this->assertNotNull($workbench, 'IRA workbench should exist in lazy AI tab payload.');
    }

    public function test_ai_workbench_refresh_endpoint_returns_html_payload(): void
    {
        [$agent, $incident] = $this->createFixture();

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.ai-workbench', $incident).'?scope=workbench')
            ->assertOk()
            ->assertJsonStructure(['generated_at', 'html'])
            ->assertJsonPath('html', fn (string $html) => str_contains($html, 'IRA Workspace'));
    }

    public function test_audit_endpoint_records_viewed_copied_and_inserted_events_without_content_body(): void
    {
        [$agent, $incident] = $this->createFixture();

        foreach (['viewed', 'copied', 'inserted'] as $action) {
            $payload = [
                'action' => $action,
                'artifact_key' => 'reply_whatsapp',
                'content_length' => 120,
                'content_hash' => hash('sha256', 'sample suggestion'),
            ];

            if ($action === 'inserted') {
                $payload['target'] = 'remark';
            }

            $this->actingAs($agent)
                ->postJson(route('dashboard.service-cases.customer-360.ai-workbench.audit', $incident), $payload)
                ->assertOk()
                ->assertJson(['status' => 'ok']);
        }

        $this->assertSame(3, AuditLog::query()->where('auditable_id', $incident->id)->count());

        $copied = AuditLog::query()
            ->where('event', AIWorkbenchAuditService::EVENT_SUGGESTION_COPIED)
            ->firstOrFail();

        $this->assertSame('reply_whatsapp', $copied->new_values['artifact_key']);
        $this->assertSame(120, $copied->new_values['content_length']);
        $this->assertArrayNotHasKey('content', $copied->new_values ?? []);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createFixture(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WB-FEAT',
            'serial_number' => '',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-WB-FEAT',
            'customer_name' => 'Workbench Feature Customer',
            'customer_email' => 'workbench-feature@example.com',
            'customer_phone' => '9111222444',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Workbench feature case',
            'description' => 'Workbench feature case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
