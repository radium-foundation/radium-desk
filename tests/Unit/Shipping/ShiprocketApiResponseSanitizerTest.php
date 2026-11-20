<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\ShiprocketApiAuditContext;
use App\Services\Shipping\ShiprocketApiResponseSanitizer;
use Tests\TestCase;

class ShiprocketApiResponseSanitizerTest extends TestCase
{
    public function test_summarize_create_order_allowlists_ids_only(): void
    {
        $summary = ShiprocketApiResponseSanitizer::summarizeForOperation('create_order', [
            'order_id' => 42,
            'shipment_id' => 99,
            'billing_email' => 'user@secret.test',
            'customer_name' => 'Secret Name',
        ], 200);

        $this->assertSame('42', $summary['provider_order_id'] ?? null);
        $this->assertSame('99', $summary['provider_shipment_id'] ?? null);
        $this->assertArrayNotHasKey('billing_email', $summary);
    }

    public function test_infer_outcome_partial_when_label_not_created(): void
    {
        $summary = ShiprocketApiResponseSanitizer::summarizeForOperation('generate_label', [
            'label_created' => 0,
            'not_created' => [1001],
        ], 200);

        $outcome = ShiprocketApiResponseSanitizer::inferOutcome('generate_label', 200, $summary, 'generated');

        $this->assertSame('partial', $outcome);
    }

    public function test_audit_payload_strips_bearer_tokens_in_messages(): void
    {
        $context = new ShiprocketApiAuditContext(
            shipmentId: 1,
            correlationId: 'corr-1',
            merchantReference: 'RBP9001',
        );

        $payload = ShiprocketApiResponseSanitizer::auditPayload(
            operation: 'assign_awb',
            httpMethod: 'POST',
            endpoint: '/courier/assign/awb',
            httpStatus: 400,
            outcome: 'rejected',
            context: $context,
            durationMs: 12,
            providerMessage: 'Rejected Bearer abc.def.ghi token',
        );

        $this->assertStringNotContainsString('abc.def.ghi', $payload['provider_message'] ?? '');
        $this->assertStringContainsString('[redacted]', $payload['provider_message'] ?? '');
        $this->assertSame('shiprocket_api_audit_v1', $payload['schema']);
    }

    public function test_rejected_http_status_maps_to_rejected_outcome(): void
    {
        $outcome = ShiprocketApiResponseSanitizer::inferOutcome(
            'search_orders',
            422,
            [],
            null,
        );

        $this->assertSame('rejected', $outcome);
    }
}
