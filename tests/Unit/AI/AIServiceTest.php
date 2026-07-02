<?php

namespace Tests\Unit\AI;

use App\Contracts\AI\AIProvider;
use App\Data\AI\AIRecommendationDTO;
use App\Data\AI\AIResponseDTO;
use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\IncidentAIContextBuilder;
use App\Services\IncidentReferenceService;
use App\Services\Knowledge\KnowledgeEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\AIContextFactory;
use Tests\Support\KnowledgeFactory;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_for_incident_returns_structured_response_from_provider(): void
    {
        $context = AIContextFactory::make();
        $knowledge = KnowledgeFactory::make();

        $knowledgeEngine = Mockery::mock(KnowledgeEngine::class);
        $knowledgeEngine->shouldReceive('forIncident')->once()->andReturn($knowledge);

        $provider = Mockery::mock(AIProvider::class);
        $provider->shouldReceive('name')->andReturn('mock');
        $provider->shouldReceive('summarizeIncident')->once()->with($context)->andReturn('Mock incident summary.');
        $provider->shouldReceive('suggestReply')->once()->with($context)->andReturn('Mock reply.');
        $provider->shouldReceive('suggestNextActions')->once()->with($context)->andReturn([
            new AIRecommendationDTO(title: 'Mock action', confidence: 0.9),
        ]);
        $provider->shouldReceive('classifyIncident')->once()->with($context)->andReturn('Mock classification');
        $provider->shouldReceive('estimateResolution')->once()->with($context)->andReturn('Unknown');
        $provider->shouldReceive('explainRecommendation')->once()->andReturn('Because mock.');

        $builder = Mockery::mock(IncidentAIContextBuilder::class);
        $builder->shouldReceive('build')->once()->andReturn($context);

        $service = new AIService(
            $knowledgeEngine,
            $builder,
            $provider,
            app(\App\Services\AI\AIContextConfidenceCalculator::class),
        );
        $response = $service->forIncident($this->createIncident());

        $this->assertInstanceOf(AIResponseDTO::class, $response);
        $this->assertInstanceOf(KnowledgeResponseDTO::class, $response->knowledge);
        $this->assertSame('Mock incident summary.', $response->incidentSummary);
        $this->assertGreaterThan(0, $response->confidenceScore);
    }

    private function createIncident(): Incident
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AI-SVC',
            'serial_number' => 'SN-AI-SVC',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Service Customer',
            'customer_phone' => '9000000003',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Service test',
            'description' => 'Service test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);
    }
}
