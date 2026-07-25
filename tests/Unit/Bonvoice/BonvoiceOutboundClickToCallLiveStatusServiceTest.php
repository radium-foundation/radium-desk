<?php

namespace Tests\Unit\Bonvoice;

use App\Enums\OutboundClickToCallLifecycleStatus;
use App\Events\Dashboard\OutboundClickToCallStatusUpdated;
use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceOutboundClickToCallLiveStatusService;
use App\Support\Bonvoice\OutboundClickToCallLifecycleNormalizer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BonvoiceOutboundClickToCallLiveStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_maybe_broadcast_dispatches_normalized_lifecycle_event(): void
    {
        Event::fake([OutboundClickToCallStatusUpdated::class]);

        $agent = User::factory()->create();

        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-unit-001',
            'leg' => 'B',
            'direction' => 'Outbound',
            'status' => 'Ringing',
            'event_id' => 'EVTUNIT1234567890',
            'callback_params' => [
                'source' => 'radium_desk',
                'user_id' => $agent->id,
                'event_id' => 'EVTUNIT1234567890',
            ],
            'payload' => [],
        ]);

        app(BonvoiceOutboundClickToCallLiveStatusService::class)->maybeBroadcast($event, null);

        Event::assertDispatched(OutboundClickToCallStatusUpdated::class, function (OutboundClickToCallStatusUpdated $broadcast) use ($agent): bool {
            return $broadcast->recipient->is($agent)
                && ($broadcast->call['lifecycle_status'] ?? null) === 'ringing';
        });
    }

    public function test_normalizer_and_terminal_flags_align(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(status: 'NOANSWER', leg: 'B');

        $this->assertSame(OutboundClickToCallLifecycleStatus::NoAnswer, $status);
        $this->assertTrue($status->isTerminal());
    }
}
