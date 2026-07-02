<?php

namespace Tests\Unit\AI;

use App\Data\AI\AIIncidentBundle;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\AIWorkbenchService;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIWorkbenchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_generates_whatsapp_email_and_internal_replies(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertSame('waiting_for_serial', $workbench->scenario);
        $this->assertCount(3, $workbench->customerReplies);
        $this->assertSame('WhatsApp', $workbench->customerReplies[0]['channel_label']);
        $this->assertStringContainsString('serial number', $workbench->customerReplies[0]['content']);
        $this->assertStringContainsString('Subject:', $workbench->customerReplies[1]['content']);
        $this->assertStringContainsString('Internal note', $workbench->customerReplies[2]['content']);
    }

    public function test_generates_internal_note_with_repeat_history_guidance(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(repeatTitle: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertStringContainsString('previous repair history', $workbench->internalNote['content']);
        $this->assertStringContainsString('technician notes', $workbench->internalNote['content']);
        $this->assertNotSame('', $workbench->internalNote['explanation']);
    }

    public function test_generates_checklist_items(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);
        $labels = array_column($workbench->checklist, 'label');

        $this->assertContains('Verify serial number', $labels);
        $this->assertContains('Verify warranty', $labels);
        $this->assertContains('Run diagnostics', $labels);
        $this->assertContains('Update customer', $labels);
    }

    public function test_generates_workflow_suggestions_without_execution(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true, warrantyExpired: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);
        $labels = array_column($workbench->workflowSuggestions, 'label');

        $this->assertContains('Assign Engineer', $labels);
        $this->assertContains('Request Serial', $labels);
        $this->assertContains('Send Estimate', $labels);
    }

    public function test_includes_confidence_explanation_from_ai_response(): void
    {
        [$incident, $bundle] = $this->createIncidentBundle(serialMissing: true);

        $workbench = app(AIWorkbenchService::class)->buildFromBundle($incident, $bundle);

        $this->assertSame($bundle->response->confidenceLevel, $workbench->confidenceLevel);
        $this->assertSame($bundle->response->confidenceScore, $workbench->confidenceScore);
        $this->assertNotNull($workbench->confidenceExplanation);
    }

    /**
     * @return array{0: Incident, 1: AIIncidentBundle}
     */
    private function createIncidentBundle(
        bool $serialMissing = false,
        bool $warrantyExpired = false,
        bool $repeatTitle = false,
    ): array {
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-WB-001',
            'customer_name' => 'Workbench Customer',
            'customer_phone' => '9111000001',
            'customer_email' => 'workbench@example.com',
            'serial_number' => $serialMissing ? '' : 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        if ($warrantyExpired) {
            app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id, [
                'warranty' => 'Expired',
                'amc' => 'Not Available',
            ]);
        }

        if ($repeatTitle) {
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Repeat sensor issue',
                'description' => 'Closed repeat.',
                'status' => IncidentStatus::Closed,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Repeat sensor issue',
            'description' => 'Open repeat.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        if ($serialMissing) {
            IncidentWaitingState::query()->create([
                'incident_id' => $incident->id,
                'waiting_reason' => WaitingReason::SerialNumber,
                'started_at' => now()->subHour(),
                'sla_paused' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $bundle = app(AIService::class)->buildBundle($incident);

        return [$incident->fresh(['order', 'activeWaitingState']), $bundle];
    }
}
