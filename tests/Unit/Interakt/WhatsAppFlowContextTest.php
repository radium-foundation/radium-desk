<?php

namespace Tests\Unit\Interakt;

use App\Data\Interakt\WhatsAppFlowContext;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppFlowContextTest extends TestCase
{
    public function test_to_array_serializes_all_fields(): void
    {
        $expiresAt = now()->addHours(24);

        $context = new WhatsAppFlowContext(
            incident_id: 42,
            incident_reference: 'SC00042',
            order_id: 'RD-FLOW-001',
            customer_name: 'Jane Customer',
            customer_phone: '9876543210',
            brand: 'Mantra',
            model: 'MFS 110 E3',
            serial_number: 'SN-FLOW-001',
            booking_url: 'https://desk.example.test/appointments/create?incident=42&signature=abc',
            expires_at: $expiresAt,
        );

        $array = $context->toArray();

        $this->assertSame(42, $array['incident_id']);
        $this->assertSame('SC00042', $array['incident_reference']);
        $this->assertSame('RD-FLOW-001', $array['order_id']);
        $this->assertSame('Jane Customer', $array['customer_name']);
        $this->assertSame('9876543210', $array['customer_phone']);
        $this->assertSame('Mantra', $array['brand']);
        $this->assertSame('MFS 110 E3', $array['model']);
        $this->assertSame('SN-FLOW-001', $array['serial_number']);
        $this->assertSame(
            'https://desk.example.test/appointments/create?incident=42&signature=abc',
            $array['booking_url'],
        );
        $this->assertSame($expiresAt->toIso8601String(), $array['expires_at']);
    }

    public function test_from_array_restores_context(): void
    {
        $expiresAt = now()->addHours(12);

        $context = WhatsAppFlowContext::fromArray([
            'incident_id' => 7,
            'incident_reference' => 'SC00007',
            'order_id' => 'RD-FLOW-007',
            'customer_name' => 'John Doe',
            'customer_phone' => '9123456780',
            'brand' => null,
            'model' => 'MFS 110',
            'serial_number' => null,
            'booking_url' => 'https://desk.example.test/book',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        $this->assertSame(7, $context->incident_id);
        $this->assertSame('SC00007', $context->incident_reference);
        $this->assertSame('RD-FLOW-007', $context->order_id);
        $this->assertSame('John Doe', $context->customer_name);
        $this->assertSame('9123456780', $context->customer_phone);
        $this->assertNull($context->brand);
        $this->assertSame('MFS 110', $context->model);
        $this->assertNull($context->serial_number);
        $this->assertSame('https://desk.example.test/book', $context->booking_url);
        $this->assertSame($expiresAt->toIso8601String(), $context->expires_at->toIso8601String());
    }

    public function test_round_trip_serialization_preserves_data(): void
    {
        $original = new WhatsAppFlowContext(
            incident_id: 99,
            incident_reference: 'SC00099',
            order_id: 'RD-ROUNDTRIP',
            customer_name: 'Round Trip',
            customer_phone: '9000000001',
            brand: 'TestBrand',
            model: 'Test Model',
            serial_number: 'SN-RT',
            booking_url: 'https://desk.example.test/signed-url',
            expires_at: Carbon::parse('2026-08-01T12:00:00+05:30'),
        );

        $restored = WhatsAppFlowContext::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
    }
}
