<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentOrderRecordIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_with_legacy_order_id_dual_writes_order_record_id(): void
    {
        [$actor, $order] = $this->seedActorAndOrder();

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TEST-1',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Legacy key create',
            'description' => 'test',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->assertSame($order->id, (int) $incident->order_id);
        $this->assertSame($order->id, (int) $incident->order_record_id);
        $this->assertTrue($incident->order->is($order));
        $this->assertTrue($order->incidents()->whereKey($incident->id)->exists());
    }

    public function test_creating_with_order_record_id_dual_writes_legacy_order_id(): void
    {
        [$actor, $order] = $this->seedActorAndOrder('RD-IDENTITY-2');

        $incident = Incident::query()->create([
            'order_record_id' => $order->id,
            'reference_no' => 'SC-TEST-2',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Preferred key create',
            'description' => 'test',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->assertSame($order->id, (int) $incident->order_id);
        $this->assertSame($order->id, (int) $incident->order_record_id);
    }

    public function test_update_keeps_both_identity_columns_in_sync(): void
    {
        [$actor, $order] = $this->seedActorAndOrder('RD-IDENTITY-3');
        $other = Order::query()->create([
            'order_id' => 'RD-IDENTITY-3B',
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-TEST-3',
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Update sync',
            'description' => 'test',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $incident->update(['order_id' => $other->id]);

        $incident->refresh();
        $this->assertSame($other->id, (int) $incident->order_id);
        $this->assertSame($other->id, (int) $incident->order_record_id);
        $this->assertSame($other->id, (int) $incident->order->id);
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function seedActorAndOrder(string $businessOrderId = 'RD-IDENTITY-1'): array
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => $businessOrderId,
            'status' => 'active',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        return [$actor, $order];
    }
}
