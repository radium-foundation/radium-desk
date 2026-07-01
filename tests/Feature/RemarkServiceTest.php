<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RemarkService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemarkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_note_stores_ai_mention_metadata(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTE-META',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Metadata test',
            'description' => 'Metadata test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $remark = app(RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Summarize this for @IRA please.',
        );

        $this->assertSame(
            ['ira'],
            $remark->fresh()->metadataDto()->aiMentions,
        );
    }

    public function test_create_note_without_ai_mention_has_null_metadata(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTE-PLAIN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Plain note',
            'description' => 'Plain note.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $remark = app(RemarkService::class)->createForRemarkable(
            remarkable: $incident,
            actor: $agent,
            body: 'Standard operational note.',
        );

        $this->assertNull($remark->fresh()->metadata);
    }
}
