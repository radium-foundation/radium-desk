<?php

namespace Tests\Unit\Timeline;

use App\Enums\TimelineEventType;
use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceAgentResolver;
use App\Services\Bonvoice\BonvoiceCustomerCallService;
use App\Services\Timeline\Sources\BonVoiceCallTimelineEventSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BonVoiceCallTimelineEventSourceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('statusVariantProvider')]
    public function test_status_variant_uses_explicit_status_mapping(string $status, string $expectedVariant): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-TL-STATUS-'.uniqid(),
            'serial_number' => 'SN-TL-1',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Timeline Customer',
            'customer_phone' => '9876501111',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-status-'.uniqid(),
            'leg' => 'call',
            'customer_phone' => '9876501111',
            'source_number' => '9876501111',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => $status,
            'started_at' => Carbon::parse('2026-07-28 10:00:00', 'Asia/Kolkata'),
            'payload' => ['CallDuration' => '0'],
        ]);

        $events = (new BonVoiceCallTimelineEventSource(
            $order,
            app(BonvoiceCustomerCallService::class),
            app(BonvoiceAgentResolver::class),
        ))->collect();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(TimelineEventType::IvrCall, $event->type);
        $this->assertSame($status, $event->statusLabel);
        $this->assertSame($expectedVariant, $event->statusVariant);
    }

    public function test_noanswer_timeline_chip_is_not_success(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-TL-NOANSWER-'.uniqid(),
            'serial_number' => 'SN-TL-NOA',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'No Answer Customer',
            'customer_phone' => '9876502222',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-noanswer-'.uniqid(),
            'leg' => 'call',
            'customer_phone' => '9876502222',
            'source_number' => '9876502222',
            'destination_number' => null,
            'direction' => 'Inbound',
            'status' => 'NOANSWER',
            'started_at' => Carbon::parse('2026-07-28 11:00:00', 'Asia/Kolkata'),
            'payload' => ['CallDuration' => '0'],
        ]);

        $event = (new BonVoiceCallTimelineEventSource(
            $order,
            app(BonvoiceCustomerCallService::class),
            app(BonvoiceAgentResolver::class),
        ))->collect()->first();

        $this->assertNotNull($event);
        $this->assertSame('NOANSWER', $event->statusLabel);
        $this->assertSame('danger', $event->statusVariant);
        $this->assertNotSame('success', $event->statusVariant);
    }

    public function test_answered_timeline_chip_remains_success(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-TL-ANSWERED-'.uniqid(),
            'serial_number' => 'SN-TL-ANS',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Answered Customer',
            'customer_phone' => '9876503333',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-answered-'.uniqid(),
            'leg' => 'call',
            'customer_phone' => '9876503333',
            'source_number' => '9876503333',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'started_at' => Carbon::parse('2026-07-28 12:00:00', 'Asia/Kolkata'),
            'payload' => ['CallDuration' => '42'],
        ]);

        $event = (new BonVoiceCallTimelineEventSource(
            $order,
            app(BonvoiceCustomerCallService::class),
            app(BonvoiceAgentResolver::class),
        ))->collect()->first();

        $this->assertNotNull($event);
        $this->assertSame('ANSWERED', $event->statusLabel);
        $this->assertSame('success', $event->statusVariant);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statusVariantProvider(): array
    {
        return [
            'answered' => ['ANSWERED', 'success'],
            'completed' => ['COMPLETED', 'success'],
            'noanswer' => ['NOANSWER', 'danger'],
            'noinput' => ['NOINPUT', 'danger'],
            'failed' => ['FAILED', 'danger'],
            'busy' => ['BUSY', 'danger'],
            'cancelled' => ['CANCELLED', 'danger'],
            'ringing' => ['RINGING', 'warning'],
            'unknown' => ['DIALING', 'neutral'],
        ];
    }
}
